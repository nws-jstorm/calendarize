<?php

/**
 * Import.
 */
declare(strict_types=1);

namespace HDNET\Calendarize\Command;

use HDNET\Calendarize\Domain\Repository\EventRepository;
use HDNET\Calendarize\Service\IndexerService;
use HDNET\Calendarize\Utility\ConfigurationUtility;
use HDNET\Calendarize\Utility\DateTimeUtility;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;

/**
 * Import.
 */
class ImportCommandController extends AbstractCommandController
{
    /**
     * Import command.
     *
     * @param string $icsCalendarUri
     * @param int $pid
     * @param bool $deleteBeforeImport Deletes all Events on same page before importing: Per default they get updated or newly created based on their imported and local uid. With this option All Events will get new created.
     */
    public function importCommand($icsCalendarUri = null, $pid = null, $deleteBeforeImport = false)
    {
        if (null === $icsCalendarUri || !\filter_var($icsCalendarUri, FILTER_VALIDATE_URL)) {
            $this->enqueueMessage('You have to enter a valid URL to the iCalendar ICS', 'Error', FlashMessage::ERROR);

            return;
        }
        if (!MathUtility::canBeInterpretedAsInteger($pid)) {
            $this->enqueueMessage('You have to enter a valid PID for the new created elements', 'Error', FlashMessage::ERROR);

            return;
        }

        $signalSlotDispatcher = GeneralUtility::makeInstance(Dispatcher::class);
        if($deleteBeforeImport) {
            $result = $signalSlotDispatcher->dispatch(__CLASS__, 'deleteCommand', [$pid,0,0]);
            if($result['removed'] > 0) {
                $this->enqueueMessage('Removed '.$result['removed'].' and kept '.$result['kept'].' of all Events before importing. (With other pid)', 'Delete Statistic', FlashMessage::INFO);
            } else {
                $this->enqueueMessage('Nothing to remove. Kept '.$result['kept'].' of all Events before importing. (With other pid)', 'Delete Statistic', FlashMessage::INFO);
            }
        }


        // fetch external URI and write to file
        $this->enqueueMessage('Start to checkout the calendar: ' . $icsCalendarUri, 'Calendar', FlashMessage::INFO);
        $relativeIcalFile = 'typo3temp/ical.' . GeneralUtility::shortMD5($icsCalendarUri) . '.ical';
        $absoluteIcalFile = GeneralUtility::getFileAbsFileName($relativeIcalFile);
        $content = GeneralUtility::getUrl($icsCalendarUri);
        GeneralUtility::writeFile($absoluteIcalFile, $content);

        // get Events from file
        $icalEvents = $this->getIcalEvents($absoluteIcalFile);
        $this->enqueueMessage('Found ' . \count($icalEvents) . ' events in the given calendar', 'Items', FlashMessage::INFO);
        $events = $this->prepareEvents($icalEvents);

        $this->enqueueMessage('Found ' . \count($events) . ' events in ' . $icsCalendarUri, 'Items', FlashMessage::INFO);

        $this->enqueueMessage('Send the ' . __CLASS__ . '::importCommand signal for each event.', 'Signal', FlashMessage::INFO);
        foreach ($events as $event) {
            $arguments = [
                'event' => $event,
                'commandController' => $this,
                'pid' => $pid,
                'handled' => false,
            ];
            $signalSlotDispatcher->dispatch(__CLASS__, 'importCommand', $arguments);
        }

        $this->enqueueMessage('Run Reindex proces after import', 'Reindex', FlashMessage::INFO);
        $indexer = $this->objectManager->get(IndexerService::class);
        $indexer->reindexAll();
    }

    /**
     * Prepare the events.
     *
     * @param array $icalEvents
     *
     * @return array
     */
    protected function prepareEvents(array $icalEvents)
    {
        $events = [];
        $timezone = DateTimeUtility::getTimeZone();

        foreach ($icalEvents as $icalEvent) {
            $startDateTime = null;
            $endDateTime = null;
            try {
                $startDateTime = new \DateTime($icalEvent['DTSTART']);
                $startDateTime->setTimezone($timezone);
                if ($icalEvent['DTEND']) {
                    $endDateTime = new \DateTime($icalEvent['DTEND']);
                    $endDateTime->setTimezone($timezone);
                } else {
                    $endDateTime = clone $startDateTime;
                    $endDateTime->add(new \DateInterval($icalEvent['DURATION']));
                }
            } catch (\Exception $ex) {
                $this->enqueueMessage(
                    'Could not convert the date in the right format of "' . $icalEvent['SUMMARY'] . '"',
                    'Warning',
                    FlashMessage::WARNING
                );
                continue;
            }

            $events[] = [
                'uid' => $icalEvent['UID'],
                'start' => $startDateTime,
                'end' => $endDateTime,
                'title' => $icalEvent['SUMMARY'] ? $icalEvent['SUMMARY'] : '',
                'description' => $icalEvent['DESCRIPTION'] ? $icalEvent['DESCRIPTION'] : '',
                'location' => $icalEvent['LOCATION'] ? $icalEvent['LOCATION'] : '',
            ];
        }

        return $events;
    }

    /**
     * Get the events from the given ical file.
     *
     * @param string $absoluteIcalFile
     *
     * @return array
     */
    protected function getIcalEvents($absoluteIcalFile)
    {
        if (!\class_exists('ICal')) {
            require_once ExtensionManagementUtility::extPath(
                'calendarize',
                'Resources/Private/Php/ics-parser/class.iCalReader.php'
            );
        }

        return (array)(new \ICal($absoluteIcalFile))->events();
    }
}
