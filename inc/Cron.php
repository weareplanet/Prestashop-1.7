<?php
/**
 * WeArePlanet Prestashop
 *
 * This Prestashop module enables to process payments with WeArePlanet (https://www.weareplanet.com/).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2025 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

class WeArePlanetCron
{
    const STATE_PENDING = 'pending';

    const STATE_PROCESSING = 'processing';

    const STATE_SUCCESS = 'success';

    const STATE_ERROR = 'error';

    const MAX_RUN_TIME_MINUTES = 10;

    public static function cleanUpHangingCrons()
    {
        WeArePlanetHelper::startDBTransaction();
        try {
            $timeout = new DateTime();
            $timeout->sub(new DateInterval('PT' . self::MAX_RUN_TIME_MINUTES . 'M'));
            $endTime = new DateTime();
            $sqlCleanUp = 'UPDATE ' . _DB_PREFIX_ . 'pln_cron_job SET constraint_key = id_cron_job, state = "' .
                pSQL(WeArePlanetCron::STATE_ERROR) . '" , date_finished = "' .
                pSQL($endTime->format('Y-m-d H:i:s')) . '", error_msg = "' .
                pSQL("Cron was not terminated correctly. Timeout exceeded.") . '" WHERE state =  "' .
                pSQL(WeArePlanetCron::STATE_PROCESSING) . '" AND date_started < "' .
                pSQL($timeout->format('Y-m-d H:i:s')) . '"';
            DB::getInstance()->execute($sqlCleanUp, false);
            WeArePlanetHelper::commitDBTransaction();
        } catch (PrestaShopDatabaseException $e) {
            WeArePlanetHelper::rollbackDBTransaction();
            PrestaShopLogger::addLog('Error updating hanging cron jobs.', 2, null, 'WeArePlanet');
        }
    }

    public static function insertNewPendingCron()
    {
        WeArePlanetHelper::startDBTransaction();
        $time = new DateTime();
        $time->add(new DateInterval('PT3M'));
        try {
            $sqlQuery = 'SELECT security_token FROM ' . _DB_PREFIX_ . 'pln_cron_job WHERE state = "' .
                pSQL(WeArePlanetCron::STATE_PENDING) . '"';
            $queryResult = DB::getInstance()->getValue($sqlQuery, false);
            if ($queryResult) {
                WeArePlanetHelper::commitDBTransaction();
                return;
            }

            $sqlInsert = 'INSERT INTO ' . _DB_PREFIX_ .
                'pln_cron_job (constraint_key, state, security_token, date_scheduled) VALUES ( -1, "' .
                pSQL(self::STATE_PENDING) . '", "' . pSQL(WeArePlanetHelper::generateUUID()) . '", "' .
                pSQL($time->format('Y-m-d H:i:s')) . '")';

            $insertResult = DB::getInstance()->execute($sqlInsert, false);
            if (! $insertResult) {
                $code = DB::getInstance()->getNumberError();
                if ($code != WeArePlanetBasemodule::MYSQL_DUPLICATE_CONSTRAINT_ERROR_CODE) {
                    PrestaShopLogger::addLog(
                        'Could not insert new pending cron job. ' . DB::getInstance()->getMsgError(),
                        2,
                        null,
                        'WeArePlanet'
                    );
                }
            }
        } catch (PrestaShopDatabaseException $e) {
            $code = DB::getInstance()->getNumberError();
            if ($code != WeArePlanetBasemodule::MYSQL_DUPLICATE_CONSTRAINT_ERROR_CODE) {
                PrestaShopLogger::addLog(
                    'Could not insert new pending cron job. ' . DB::getInstance()->getMsgError(),
                    2,
                    null,
                    'WeArePlanet'
                );
            }
        }
        WeArePlanetHelper::commitDBTransaction();
    }

    public static function getAllCronJobs()
    {
        $result = DB::getInstance()->query(
            'SELECT * FROM ' . _DB_PREFIX_ . 'pln_cron_job ORDER BY id_cron_job DESC',
            false
        );
        $jobs = array();
        while ($row = DB::getInstance()->nextRow($result)) {
            $jobs[] = $row;
        }
        return $jobs;
    }

    /**
     * Returns the current token or false if no pending job is scheduled to run
     *
     * @return string|false
     */
    public static function getCurrentSecurityTokenForPendingCron()
    {
        $now = new DateTime();
        $sqlQuery = 'SELECT security_token FROM ' . _DB_PREFIX_ . 'pln_cron_job WHERE state = "' .
            pSQL(WeArePlanetCron::STATE_PENDING) . '" AND date_scheduled < "' .
            pSQL($now->format('Y-m-d H:i:s')) . '"';
        $queryResult = DB::getInstance()->getValue($sqlQuery, false);
        return $queryResult;
    }

    public static function cleanUpCronDB()
    {
        $sqlQuery = 'DELETE FROM ' . _DB_PREFIX_ . 'pln_cron_job WHERE (state = "' .
            pSQL(WeArePlanetCron::STATE_SUCCESS) . '" OR state = "' .
            pSQL(WeArePlanetCron::STATE_ERROR) . '") AND id_cron_job <= (
                SELECT id_cron_job
                FROM (
                  SELECT id_cron_job
                  FROM ' . _DB_PREFIX_ . 'pln_cron_job
                  ORDER BY id_cron_job DESC
                  LIMIT 1 OFFSET 50
                ) tmp
              );';
        DB::getInstance()->execute($sqlQuery, false);
    }
}
