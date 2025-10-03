<?php

/**
 * Appender to send log messages using Graylog with GELF TCP.
 * 
 * sample log4php.xml that implement RollingFile and Gelf (Graylog) appenders
 * 
 * <?xml version="1.0" encoding="UTF-8" ?>
 * <configuration xmlns="http://logging.apache.org/log4php/">
 * 
 *     <appender name="RollingFile" class="LoggerAppenderRollingFile">
 *         <layout class="LoggerLayoutPattern">
 *             <param name="conversionPattern" value="%date{Y-m-d H:i:s,u} %-5level %logger (line:%line) %msg%n%exception" />
 *         </layout>
 *         <param name="file" value="logs/myApp.log" />
 *         <param name="maxFileSize" value="5MB" />
 *         <param name="maxBackupIndex" value="5" />
 *     </appender>
 * 
 *     <appender name="Graylog" class="LoggerAppenderGelf">
 *         <param name="host" value="192.168.1.100" />
 *         <param name="port" value="12201" />
 *         <param name="facility" value="myFacilityName" />
 *         <param name="application" value="myApplicationName" />
 *     </appender>
 * 
 *     <root>
 *         <level value="DEBUG" />
 *         <appender_ref ref="RollingFile" />
 *         <appender_ref ref="Graylog" />
 *     </root>
 * 
 * </configuration>
 * 
 * Add into index.php to log also client ip address and user logged
 * 
 * if (isset($_SESSION['user_id'])) {
 *     LoggerMDC::put('logged_user', $_SESSION['user_id']);
 * }
 * 
 * LoggerMDC::put('client_ip', $_SERVER['REMOTE_ADDR']);
 * 
 */
class LoggerAppenderGelf extends LoggerAppender {

    protected $host = '127.0.0.1';
    protected $port = 12201;
    protected $facility = 'log4php';
    protected $application = 'myApp';

    private $socket;
    private $isConnected = false;

    public function activateOptions() {
    }

    public function append(LoggerLoggingEvent $event) {

        $fullMessage = $event->getRenderedMessage();
        $shortMessage = $fullMessage;

        if (strlen($shortMessage) > 250) {
            $shortMessage = substr($shortMessage, 0, 247) . '...';
        }

        $message = [
            'version'       => '1.1',            
            'short_message' => $shortMessage,
            'full_message'  => $fullMessage,
            'timestamp'     => microtime(true),
            'facility'      => $this->facility,
            'application'   => $this->application,
            '_source'       => gethostname(),
            '_severity'     => $this->mapLevel($event->getLevel()->toInt()),
            '_logger'       => $event->getLoggerName(),
        ];
        
        

        // check for client ip
        $clientIp = LoggerMDC::get('client_ip');
        if ($clientIp) {
            // save also client_ip info
            $message['_client_ip'] = $clientIp;
        }

        // check for logged user
        $logged_user = LoggerMDC::get('logged_user');
        if ($logged_user) {
            // save also logged_user info
            $message['_logged_user'] = $logged_user;
        }

        $json = json_encode($message);

        $url = "http://{$this->host}:{$this->port}/gelf";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $httpCode >= 400) {
            $this->warn("Error sending GELF HTTP to Graylog: $err (HTTP $httpCode)");
        }
    }

    public function close() {

    }

    private function mapLevel($level) {

        switch ($level) {
            case LoggerLevel::FATAL: return "Fatal"; // 2
            case LoggerLevel::ERROR: return "Error"; // 3
            case LoggerLevel::WARN:  return "Warning"; // 4
            case LoggerLevel::INFO:  return "Info"; // 6
            case LoggerLevel::DEBUG: return "Debug"; // 7
            default:                 return "Alert"; // 1
        }
    }

    public function setHost($host) { $this->host = $host; }
    public function setPort($port) { $this->port = (int)$port; }
    public function setFacility($facility) { $this->facility = $facility; }
    public function setApplication($application) { $this->application = $application; }
    
}
