<?php
namespace Espo\Custom\Classes\DuplicateWhereBuilders;

use Espo\Core\Duplicate\WhereBuilder;

use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\Part\Where\OrGroup;
use Espo\ORM\Entity;

class Contact implements WhereBuilder
{
    public function build(Entity $entity): ?WhereItem
    {
        $orBuilder = OrGroup::createBuilder();

        $toCheck = false;

        if ($entity->get('firstName') || $entity->get('lastName')) {
            $orBuilder->add(
                Cond::and(
                    Cond::equal(
                        Cond::column('firstName'),
                        $entity->get('firstName')
                    ),
                    Cond::equal(
                        Cond::column('lastName'),
                        $entity->get('lastName')
                    )
                )
            );

            $toCheck = true;
        }

        if ($entity->get('registrationID')) {
            $orBuilder->add(
               Cond::equal(
                        Cond::column('registrationID'),
                        $entity->get('registrationID')
                )
            );

            $toCheck = true;
        }

        // Here you can add more conditions.

        if (!$toCheck) {
            return null;
        }

        return $orBuilder->build();
    }
}
