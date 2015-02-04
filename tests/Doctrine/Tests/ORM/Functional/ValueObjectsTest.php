<?php

namespace Doctrine\Tests\ORM\Functional;

/**
 * @group DDC-93
 */
class ValueObjectsTest extends \Doctrine\Tests\OrmFunctionalTestCase
{
    public function setUp()
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(array(
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC93Person'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC93Address'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC93Vehicle'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC93Car'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC3027Animal'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC3027Dog'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC3529Event'),
            ));
        } catch(\Exception $e) {
        }
    }

    public function testCRUD()
    {
        $person = new DDC93Person();
        $person->name = "Tara";
        $person->address = new DDC93Address();
        $person->address->street = "United States of Tara Street";
        $person->address->zip = "12345";
        $person->address->city = "funkytown";
        $person->address->country = new DDC93Country('Germany');

        // 1. check saving value objects works
        $this->_em->persist($person);
        $this->_em->flush();

        $this->_em->clear();

        // 2. check loading value objects works
        $person = $this->_em->find(DDC93Person::CLASSNAME, $person->id);

        $this->assertInstanceOf(DDC93Address::CLASSNAME, $person->address);
        $this->assertEquals('United States of Tara Street', $person->address->street);
        $this->assertEquals('12345', $person->address->zip);
        $this->assertEquals('funkytown', $person->address->city);
        $this->assertInstanceOf(DDC93Country::CLASSNAME, $person->address->country);
        $this->assertEquals('Germany', $person->address->country->name);

        // 3. check changing value objects works
        $person->address->street = "Street";
        $person->address->zip = "54321";
        $person->address->city = "another town";
        $person->address->country->name = "United States of America";
        $this->_em->flush();

        $this->_em->clear();

        $person = $this->_em->find(DDC93Person::CLASSNAME, $person->id);

        $this->assertEquals('Street', $person->address->street);
        $this->assertEquals('54321', $person->address->zip);
        $this->assertEquals('another town', $person->address->city);
        $this->assertEquals('United States of America', $person->address->country->name);

        // 4. check deleting works
        $personId = $person->id;;
        $this->_em->remove($person);
        $this->_em->flush();

        $this->assertNull($this->_em->find(DDC93Person::CLASSNAME, $personId));
    }

    public function testLoadDql()
    {
        for ($i = 0; $i < 3; $i++) {
            $person = new DDC93Person();
            $person->name = "Donkey Kong$i";
            $person->address = new DDC93Address();
            $person->address->street = "Tree";
            $person->address->zip = "12345";
            $person->address->city = "funkytown";
            $person->address->country = new DDC93Country('United States of America');

            $this->_em->persist($person);
        }

        $this->_em->flush();
        $this->_em->clear();

        $dql = "SELECT p FROM " . __NAMESPACE__ . "\DDC93Person p";
        $persons = $this->_em->createQuery($dql)->getResult();

        $this->assertCount(3, $persons);
        foreach ($persons as $person) {
            $this->assertInstanceOf(DDC93Address::CLASSNAME, $person->address);
            $this->assertEquals('Tree', $person->address->street);
            $this->assertEquals('12345', $person->address->zip);
            $this->assertEquals('funkytown', $person->address->city);
            $this->assertInstanceOf(DDC93Country::CLASSNAME, $person->address->country);
            $this->assertEquals('United States of America', $person->address->country->name);
        }

        $dql = "SELECT p FROM " . __NAMESPACE__ . "\DDC93Person p";
        $persons = $this->_em->createQuery($dql)->getArrayResult();

        foreach ($persons as $person) {
            $this->assertEquals('Tree', $person['address.street']);
            $this->assertEquals('12345', $person['address.zip']);
            $this->assertEquals('funkytown', $person['address.city']);
            $this->assertEquals('United States of America', $person['address.country.name']);
        }
    }

    /**
     * @group dql
     */
    public function testDqlOnEmbeddedObjectsField()
    {
        if ($this->isSecondLevelCacheEnabled) {
            $this->markTestSkipped('SLC does not work with UPDATE/DELETE queries through EM.');
        }

        $person = new DDC93Person('Johannes', new DDC93Address('Moo', '12345', 'Karlsruhe', new DDC93Country('Germany')));
        $this->_em->persist($person);
        $this->_em->flush($person);

        // SELECT
        $selectDql = "SELECT p FROM " . __NAMESPACE__ ."\\DDC93Person p WHERE p.address.city = :city AND p.address.country.name = :country";
        $loadedPerson = $this->_em->createQuery($selectDql)
            ->setParameter('city', 'Karlsruhe')
            ->setParameter('country', 'Germany')
            ->getSingleResult();
        $this->assertEquals($person, $loadedPerson);

        $this->assertNull(
            $this->_em->createQuery($selectDql)
                ->setParameter('city', 'asdf')
                ->setParameter('country', 'Germany')
                ->getOneOrNullResult()
        );

        // UPDATE
        $updateDql = "UPDATE " . __NAMESPACE__ . "\\DDC93Person p SET p.address.street = :street, p.address.country.name = :country WHERE p.address.city = :city";
        $this->_em->createQuery($updateDql)
            ->setParameter('street', 'Boo')
            ->setParameter('country', 'DE')
            ->setParameter('city', 'Karlsruhe')
            ->execute();

        $this->_em->refresh($person);
        $this->assertEquals('Boo', $person->address->street);
        $this->assertEquals('DE', $person->address->country->name);

        // DELETE
        $this->_em->createQuery("DELETE " . __NAMESPACE__ . "\\DDC93Person p WHERE p.address.city = :city AND p.address.country.name = :country")
            ->setParameter('city', 'Karlsruhe')
            ->setParameter('country', 'DE')
            ->execute();

        $this->_em->clear();
        $this->assertNull($this->_em->find(__NAMESPACE__.'\\DDC93Person', $person->id));
    }

    public function testDqlWithNonExistentEmbeddableField()
    {
        $this->setExpectedException('Doctrine\ORM\Query\QueryException', 'no field or association named address.asdfasdf');

        $this->_em->createQuery("SELECT p FROM " . __NAMESPACE__ . "\\DDC93Person p WHERE p.address.asdfasdf IS NULL")
            ->execute();
    }

    public function testEmbeddableWithInheritance()
    {
        $car = new DDC93Car(new DDC93Address('Foo', '12345', 'Asdf'));
        $this->_em->persist($car);
        $this->_em->flush($car);

        $reloadedCar = $this->_em->find(__NAMESPACE__.'\\DDC93Car', $car->id);
        $this->assertEquals($car, $reloadedCar);
    }

    public function testInlineEmbeddableWithPrefix()
    {
        $metadata = $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC3028PersonWithPrefix');

        $this->assertEquals('foobar_id', $metadata->getColumnName('id.id'));
        $this->assertEquals('bloo_foo_id', $metadata->getColumnName('nested.nestedWithPrefix.id'));
        $this->assertEquals('bloo_nestedWithEmptyPrefix_id', $metadata->getColumnName('nested.nestedWithEmptyPrefix.id'));
        $this->assertEquals('bloo_id', $metadata->getColumnName('nested.nestedWithPrefixFalse.id'));
    }

    public function testInlineEmbeddableEmptyPrefix()
    {
        $metadata = $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC3028PersonEmptyPrefix');

        $this->assertEquals('id_id', $metadata->getColumnName('id.id'));
        $this->assertEquals('nested_foo_id', $metadata->getColumnName('nested.nestedWithPrefix.id'));
        $this->assertEquals('nested_nestedWithEmptyPrefix_id', $metadata->getColumnName('nested.nestedWithEmptyPrefix.id'));
        $this->assertEquals('nested_id', $metadata->getColumnName('nested.nestedWithPrefixFalse.id'));
    }

    public function testInlineEmbeddablePrefixFalse()
    {
        $expectedColumnName = 'id';

        $actualColumnName = $this->_em
            ->getClassMetadata(__NAMESPACE__ . '\DDC3028PersonPrefixFalse')
            ->getColumnName('id.id');

        $this->assertEquals($expectedColumnName, $actualColumnName);
    }

    public function testInlineEmbeddableInMappedSuperClass()
    {
        $isFieldMapped = $this->_em
            ->getClassMetadata(__NAMESPACE__ . '\DDC3027Dog')
            ->hasField('address.street');

        $this->assertTrue($isFieldMapped);
    }

    /**
     * @dataProvider getInfiniteEmbeddableNestingData
     */
    public function testThrowsExceptionOnInfiniteEmbeddableNesting($embeddableClassName, $declaredEmbeddableClassName)
    {
        $this->setExpectedException(
            'Doctrine\ORM\Mapping\MappingException',
            sprintf(
                'Infinite nesting detected for embedded property %s::nested. ' .
                'You cannot embed an embeddable from the same type inside an embeddable.',
                __NAMESPACE__ . '\\' . $declaredEmbeddableClassName
            )
        );

        $this->_schemaTool->createSchema(array(
            $this->_em->getClassMetadata(__NAMESPACE__ . '\\' . $embeddableClassName),
        ));
    }

    public function testNoErrorsShouldHappenWhenPersistingAnEntityWithNullableEmbedded()
    {
        $event = new DDC3529Event();
        $event->name = 'PHP Conference';
        $event->period = new DDC3529DateInterval(new \DateTime('2015-01-20 08:00:00'), new \DateTime('2015-01-23 19:00:00'));

        $this->_em->persist($event);
        $this->_em->flush();

        return $event;
    }

    /**
     * @depends testNoErrorsShouldHappenWhenPersistingAnEntityWithNullableEmbedded
     * @param DDC3529Event $event
     */
    public function testEmbeddedObjectShouldNotBeCreatedWhenIsNullableAndHaveNoData(DDC3529Event $event)
    {
        $event = $this->_em->find(DDC3529Event::CLASSNAME, $event->id);

        $this->assertEquals('PHP Conference', $event->name);
        $this->assertEquals('2015-01-20 08:00:00', $event->period->begin->format('Y-m-d H:i:s'));
        $this->assertEquals('2015-01-23 19:00:00', $event->period->end->format('Y-m-d H:i:s'));
        $this->assertNull($event->submissions);

        return $event;
    }

    /**
     * @depends testEmbeddedObjectShouldNotBeCreatedWhenIsNullableAndHaveNoData
     * @param DDC3529Event $event
     */
    public function testEmbeddedObjectShouldBeCreatedWhenIsNullableButHaveData(DDC3529Event $event)
    {
        $event->submissions = new DDC3529DateInterval(new \DateTime('2014-11-20 08:00:00'), new \DateTime('2014-12-23 19:00:00'));

        $this->_em->persist($event);
        $this->_em->flush();
        $this->_em->clear();

        $event = $this->_em->find(DDC3529Event::CLASSNAME, $event->id);

        $this->assertEquals('2014-11-20 08:00:00', $event->submissions->begin->format('Y-m-d H:i:s'));
        $this->assertEquals('2014-12-23 19:00:00', $event->submissions->end->format('Y-m-d H:i:s'));

        return $event;
    }

    /**
     * @depends testEmbeddedObjectShouldBeCreatedWhenIsNullableButHaveData
     * @param DDC3529Event $event
     */
    public function testFindShouldReturnNullAfterTheObjectWasRemoved(DDC3529Event $event)
    {
        $eventId = $event->id;

        $event = $this->_em->find(DDC3529Event::CLASSNAME, $eventId);
        $this->_em->remove($event);
        $this->_em->flush();

        $this->assertNull($this->_em->find(DDC3529Event::CLASSNAME, $eventId));
    }

    public function getInfiniteEmbeddableNestingData()
    {
        return array(
            array('DDCInfiniteNestingEmbeddable', 'DDCInfiniteNestingEmbeddable'),
            array('DDCNestingEmbeddable1', 'DDCNestingEmbeddable4'),
        );
    }
}


/**
 * @Entity
 */
class DDC93Person
{
    const CLASSNAME = __CLASS__;

    /** @Id @GeneratedValue @Column(type="integer") */
    public $id;

    /** @Column(type="string") */
    public $name;

    /** @Embedded(class="DDC93Address") */
    public $address;

    /** @Embedded(class = "DDC93Timestamps") */
    public $timestamps;

    public function __construct($name = null, DDC93Address $address = null)
    {
        $this->name = $name;
        $this->address = $address;
        $this->timestamps = new DDC93Timestamps(new \DateTime);
    }
}

/**
 * @Embeddable
 */
class DDC3529DateInterval
{
    /** @Column(type = "datetime") */
    public $begin;

    /** @Column(type = "datetime") */
    public $end;

    public function __construct(\DateTime $begin, \DateTime $end)
    {
        $this->begin = $begin;
        $this->end = $end;
    }
}

/**
 * @Entity
 */
class DDC3529Event
{
    const CLASSNAME = __CLASS__;

    /** @Id @GeneratedValue @Column(type="integer") */
    public $id;

    /** @Column(type = "string") */
    public $name;

    /** @Embedded(class = "DDC3529DateInterval") */
    public $period;

    /** @Embedded(class = "DDC3529DateInterval", nullable = true) */
    public $submissions;
}

/**
 * @Embeddable
 */
class DDC93Timestamps
{
    /** @Column(type = "datetime") */
    public $createdAt;

    public function __construct(\DateTime $createdAt)
    {
        $this->createdAt = $createdAt;
    }
}

/**
 * @Entity
 *
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name = "t", type = "string", length = 10)
 * @DiscriminatorMap({
 *     "v" = "Doctrine\Tests\ORM\Functional\DDC93Car",
 * })
 */
abstract class DDC93Vehicle
{
    /** @Id @GeneratedValue(strategy = "AUTO") @Column(type = "integer") */
    public $id;

    /** @Embedded(class = "DDC93Address") */
    public $address;

    public function __construct(DDC93Address $address)
    {
        $this->address = $address;
    }
}

/**
 * @Entity
 */
class DDC93Car extends DDC93Vehicle
{
}

/**
 * @Embeddable
 */
class DDC93Country
{
    const CLASSNAME = __CLASS__;

    /**
     * @Column(type="string", nullable=true)
     */
    public $name;

    public function __construct($name = null)
    {
        $this->name = $name;
    }
}

/**
 * @Embeddable
 */
class DDC93Address
{
    const CLASSNAME = __CLASS__;

    /**
     * @Column(type="string")
     */
    public $street;
    /**
     * @Column(type="string")
     */
    public $zip;
    /**
     * @Column(type="string")
     */
    public $city;
    /** @Embedded(class = "DDC93Country") */
    public $country;

    public function __construct($street = null, $zip = null, $city = null, DDC93Country $country = null)
    {
        $this->street = $street;
        $this->zip = $zip;
        $this->city = $city;
        $this->country = $country;
    }
}

/** @Entity */
class DDC93Customer
{
    /** @Id @GeneratedValue @Column(type="integer") */
    private $id;

    /** @Embedded(class = "DDC93ContactInfo", columnPrefix = "contact_info_") */
    private $contactInfo;
}

/** @Embeddable */
class DDC93ContactInfo
{
    const CLASSNAME = __CLASS__;

    /**
     * @Column(type="string")
     */
    public $email;
    /** @Embedded(class = "DDC93Address") */
    public $address;
}

/**
 * @Entity
 */
class DDC3028PersonWithPrefix
{
    const CLASSNAME = __CLASS__;

    /** @Embedded(class="DDC3028Id", columnPrefix = "foobar_") */
    public $id;

    /** @Embedded(class="DDC3028NestedEmbeddable", columnPrefix = "bloo_") */
    public $nested;

    public function __construct(DDC3028Id $id = null, DDC3028NestedEmbeddable $nested = null)
    {
        $this->id = $id;
        $this->nested = $nested;
    }
}

/**
 * @Entity
 */
class DDC3028PersonEmptyPrefix
{
    const CLASSNAME = __CLASS__;

    /** @Embedded(class="DDC3028Id", columnPrefix = "") */
    public $id;

    /** @Embedded(class="DDC3028NestedEmbeddable", columnPrefix = "") */
    public $nested;

    public function __construct(DDC3028Id $id = null, DDC3028NestedEmbeddable $nested = null)
    {
        $this->id = $id;
        $this->nested = $nested;
    }
}

/**
 * @Entity
 */
class DDC3028PersonPrefixFalse
{
    const CLASSNAME = __CLASS__;

    /** @Embedded(class="DDC3028Id", columnPrefix = false) */
    public $id;

    public function __construct(DDC3028Id $id = null)
    {
        $this->id = $id;
    }
}

/**
 * @Embeddable
 */
class DDC3028Id
{
    const CLASSNAME = __CLASS__;

    /**
     * @Id @Column(type="string")
     */
    public $id;

    public function __construct($id = null)
    {
        $this->id = $id;
    }
}

/**
 * @Embeddable
 */
class DDC3028NestedEmbeddable
{
    const CLASSNAME = __CLASS__;

    /** @Embedded(class="DDC3028Id", columnPrefix = "foo_") */
    public $nestedWithPrefix;

    /** @Embedded(class="DDC3028Id", columnPrefix = "") */
    public $nestedWithEmptyPrefix;

    /** @Embedded(class="DDC3028Id", columnPrefix = false) */
    public $nestedWithPrefixFalse;

    public function __construct(
        DDC3028Id $nestedWithPrefix = null,
        DDC3028Id $nestedWithEmptyPrefix = null,
        DDC3028Id $nestedWithPrefixFalse = null
    ) {
        $this->nestedWithPrefix = $nestedWithPrefix;
        $this->nestedWithEmptyPrefix = $nestedWithEmptyPrefix;
        $this->nestedWithPrefixFalse = $nestedWithPrefixFalse;
    }
}

/**
 * @MappedSuperclass
 */
abstract class DDC3027Animal
{
    /** @Id @GeneratedValue(strategy = "AUTO") @Column(type = "integer") */
    public $id;

    /** @Embedded(class = "DDC93Address") */
    public $address;
}

/**
 * @Entity
 */
class DDC3027Dog extends DDC3027Animal
{
}

/**
 * @Embeddable
 */
class DDCInfiniteNestingEmbeddable
{
    /** @Embedded(class="DDCInfiniteNestingEmbeddable") */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable1
{
    /** @Embedded(class="DDC3028Id") */
    public $id1;

    /** @Embedded(class="DDC3028Id") */
    public $id2;

    /** @Embedded(class="DDCNestingEmbeddable2") */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable2
{
    /** @Embedded(class="DDC3028Id") */
    public $id1;

    /** @Embedded(class="DDC3028Id") */
    public $id2;

    /** @Embedded(class="DDCNestingEmbeddable3") */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable3
{
    /** @Embedded(class="DDC3028Id") */
    public $id1;

    /** @Embedded(class="DDC3028Id") */
    public $id2;

    /** @Embedded(class="DDCNestingEmbeddable4") */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable4
{
    /** @Embedded(class="DDC3028Id") */
    public $id1;

    /** @Embedded(class="DDC3028Id") */
    public $id2;

    /** @Embedded(class="DDCNestingEmbeddable1") */
    public $nested;
}
