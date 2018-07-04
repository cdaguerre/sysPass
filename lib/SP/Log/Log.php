<?php
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2018, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Log;

use SP\Core\Exceptions\SPException;
use SP\Core\Messages\LogMessage;
use SP\Storage\Database\QueryData;
use SP\Util\HttpUtil;
use SP\Util\Util;

defined('APP_ROOT') || die();

/**
 * Esta clase es la encargada de manejar el registro de eventos
 */
class Log extends ActionLog
{
    /**
     * @var int
     */
    public static $numRows = 0;
    /**
     * @var int
     */
    private static $logDbEnabled = 1;

    /**
     * Obtener los eventos guardados.
     *
     * @param int $start con el número de registro desde el que empezar
     * @param int $count Número de registros por consulta
     * @return array|false con el resultado de la consulta
     */
    public static function getEvents($start, $count)
    {
        $Data = new QueryData();
        $Data->setSelect('log_id,FROM_UNIXTIME(log_date) AS log_date,log_action,log_level,log_login,log_ipAddress,log_description');
        $Data->setFrom('log');
        $Data->setOrder('log_id DESC');
        $Data->setLimit('?, ?');
        $Data->addParam($start);
        $Data->addParam($count);

        // Obtenemos el número total de registros
        DbWrapper::setFullRowCount();

        $queryRes = DbWrapper::getResultsArray($Data);

        self::$numRows = $Data->getQueryNumRows();

        return $queryRes;
    }

    /**
     * Limpiar el registro de eventos.
     *
     * @return bool con el resultado
     * @throws \SP\Core\Exceptions\QueryException
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\SPException
     */
    public static function clearEvents()
    {
        $query = 'TRUNCATE TABLE log';

        $Data = new QueryData();
        $Data->setQuery($query);
        $Data->setOnErrorMessage(__('Error al vaciar el registro de eventos', false));
    }

    /**
     * Obtener una nueva instancia de la clase inicializada
     *
     * @param string $action      La acción realizada
     * @param string $description La descripción de la acción realizada
     * @param string $level
     * @return Log
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public static function writeNewLogAndEmail($action, $description = null, $level = Log::INFO)
    {
        $Log = Log::writeNewLog($action, $description, $level);
        Email::sendEmail($Log->getLogMessage());

        return $Log;
    }

    /**
     * Escribir un nuevo evento en el registro de eventos
     *
     * @param string $action      La acción realizada
     * @param string $description La descripción de la acción realizada
     * @param string $level
     * @return Log
     * @throws \SP\Core\Dic\ContainerException
     */
    public static function writeNewLog($action, $description = null, $level = Log::INFO)
    {
        $LogMessage = new LogMessage();
        $LogMessage->setAction($action);
        $LogMessage->addDescription($description);

        $Log = new Log($LogMessage, $level);
        $Log->writeLog();

        return $Log;
    }

    /**
     * Escribir un nuevo evento en el registro de eventos
     *
     * @param bool $resetDescription Restablecer la descripción
     * @param bool $resetDetails     Restablecer los detalles
     * @return bool
     */
    public function writeLog($resetDescription = false, $resetDetails = false)
    {
        if (self::$logDbEnabled === 0
            || $this->db->getDbHandler()->getDbStatus() === 1
        ) {
            debugLog('Action: ' . $this->LogMessage->getAction(true) . ' -- Description: ' . $this->LogMessage->getDescription(true) . ' -- Details: ' . $this->LogMessage->getDetails(true));

            return false;
        }

        if (!$this->configData->isLogEnabled()) {
            return false;
        }

        $this->language->setAppLocales();

        if ($this->configData->isSyslogEnabled()) {
            $this->sendToSyslog();
        }

        $description = trim($this->LogMessage->getDescription(true) . PHP_EOL . $this->LogMessage->getDetails(true));

        $query = 'INSERT INTO EventLog SET 
            date = UNIX_TIMESTAMP(),
            login = ?,
            userId = ?,
            ipAddress = ?,
            action = ?,
            level = ?,
            description = ?';

        $Data = new QueryData();
        $Data->setQuery($query);
        $Data->addParam($this->session->getUserData()->getLogin());
        $Data->addParam($this->session->getUserData()->getId());
        $Data->addParam(HttpUtil::getClientAddress(true));
        $Data->addParam(utf8_encode($this->LogMessage->getAction(true)));
        $Data->addParam($this->getLogLevel());
        $Data->addParam(utf8_encode($description));

        if ($resetDescription === true) {
            $this->LogMessage->resetDescription();
        }

        if ($resetDetails === true) {
            $this->LogMessage->resetDetails();
        }

        try {
            DbWrapper::getQuery($Data);
        } catch (SPException $e) {
            debugLog(__($e->getMessage()), true);
            debugLog(__($e->getHint()));

            // Desactivar el log a BD si falla
            self::$logDbEnabled = 0;
        }

        $this->language->unsetAppLocales();

        return true;
    }

    /**
     * Enviar mensaje al syslog
     */
    private function sendToSyslog()
    {
        $description = trim($this->LogMessage->getDescription(true) . PHP_EOL . $this->LogMessage->getDetails(true));

        $msg = 'CEF:0|sysPass|logger|' . Util::getVersionStringNormalized() . '|';
        $msg .= $this->LogMessage->getAction(true) . '|';
        $msg .= $description . '|';
        $msg .= '0|';
        $msg .= sprintf('ip_addr="%s" user_name="%s"', HttpUtil::getClientAddress(), $this->session->getUserData()->getLogin());

        $Syslog = new Syslog();
        $Syslog->setIsRemote($this->configData->isSyslogRemoteEnabled());
        $Syslog->info($msg);
    }

    /**
     * Obtener una nueva instancia de la clase inicializada
     *
     * @param string $action      La acción realizada
     * @param string $description La descripción de la acción realizada
     * @param string $level
     * @return Log
     */
    public static function newLog($action, $description = null, $level = Log::INFO)
    {
        $LogMessage = new LogMessage();
        $LogMessage->setAction($action);

        if ($description !== null) {
            $LogMessage->addDescription($description);
        }

        return new Log($LogMessage, $level);
    }
}