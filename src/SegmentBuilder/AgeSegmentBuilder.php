<?php

/**
 * Pimcore Customer Management Framework Bundle
 * Full copyright and license information is available in
 * License.md which is distributed with this source code.
 *
 * @copyright  Copyright (C) Elements.at New Media Solutions GmbH
 * @license    GPLv3
 */

namespace CustomerManagementFrameworkBundle\SegmentBuilder;

use CustomerManagementFrameworkBundle\DataTransformer\Date\TimestampToAge;
use CustomerManagementFrameworkBundle\Model\CustomerInterface;
use CustomerManagementFrameworkBundle\SegmentManager\SegmentManagerInterface;
use CustomerManagementFrameworkBundle\Traits\LoggerAware;
use Pimcore\Model\Tool\TmpStore;
use Psr\Log\LoggerInterface;
use Zend\Paginator\Paginator;

class AgeSegmentBuilder extends AbstractSegmentBuilder
{
    use LoggerAware;

    private $groupName;
    private $segmentGroup;
    private $ageGroups;
    private $birthDayField;

    public function __construct($groupName = 'Age', $ageGroups = [], $birthDayField = 'birthDate')
    {

        $this->groupName = $groupName;

        $this->ageGroups = sizeof($ageGroups) ? $ageGroups: [
            [0, 10],
            [11, 15],
            [16, 18],
            [18, 25],
            [26, 30],
            [31, 40],
            [41, 50],
            [51, 60],
            [61, 70],
            [71, 80],
            [81, 120],
        ];

        $this->birthDayField = $birthDayField;
    }

    /**
     * prepare data and configurations which could be reused for all calculateSegments() calls
     *
     * @param SegmentManagerInterface $segmentManager
     *
     * @return void
     */
    public function prepare(SegmentManagerInterface $segmentManager)
    {
        $this->segmentGroup = $segmentManager->createSegmentGroup($this->groupName, $this->groupName, true);
    }

    /**
     * build segment(s) for given customer
     *
     * @param CustomerInterface $customer
     * @param SegmentManagerInterface $segmentManager
     *
     * @return void
     */
    public function calculateSegments(CustomerInterface $customer, SegmentManagerInterface $segmentManager)
    {
        $ageSegment = null;

        $getter = 'get'.ucfirst($this->birthDayField);
        if ($birthDate = $customer->$getter()) {
            $timestamp = $birthDate->getTimestamp();

            $transformer = new TimestampToAge();
            $age = $transformer->transform($timestamp, []);

            $this->getLogger()->debug(sprintf('age of customer ID %s: %s years', $customer->getId(), $age));

            foreach ($this->ageGroups as $ageGroup) {
                $from = $ageGroup[0];
                $to = $ageGroup[1];

                if ($age >= $from && $age <= $to) {
                    $ageSegment = $segmentManager->createCalculatedSegment($from.' - '.$to, $this->groupName);
                }
            }
        }

        $segments = [];
        if ($ageSegment) {
            $segments[] = $ageSegment;
        }

        $segmentManager->mergeSegments(
            $customer,
            $segments,
            $segmentManager->getSegmentsFromSegmentGroup($this->segmentGroup, $segments),
            'AgeSegmentBuilder'
        );
    }

    /**
     * return the name of the segment builder
     *
     * @return string
     */
    public function getName()
    {
        return 'AgeSegmentBuilder';
    }

    public function executeOnCustomerSave()
    {
        return true;
    }

    public function maintenance(SegmentManagerInterface $segmentManager)
    {
        $tmpStoreKey = 'plugin_cmf_age_segment_builder';

        if (TmpStore::get($tmpStoreKey)) {
            return;
        }

        $this->logger->info('execute maintenance of AgeSegmentBuilder');

        TmpStore::add($tmpStoreKey, 1, null, (60 * 60 * 24)); // only execute it once per day

        $this->prepare($segmentManager);

        $list = \Pimcore::getContainer()->get('cmf.customer_provider')->getList();
        $list->setCondition(
            'DATE_FORMAT(FROM_UNIXTIME('.$this->birthDayField."),'%m-%d') = DATE_FORMAT(NOW(),'%m-%d')"
        );

        $paginator = new Paginator($list);
        $paginator->setItemCountPerPage(100);

        $pageCount = $paginator->getPages()->pageCount;
        for ($i = 1; $i <= $pageCount; $i++) {
            $paginator->setCurrentPageNumber($i);

            foreach ($paginator as $customer) {
                $this->calculateSegments($customer, $segmentManager);
            }
        }
    }
}
