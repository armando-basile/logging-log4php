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
 *         <param name="facility" value="myApplicationName" />
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
        $this->connect();
    }

    private function connect() {

        $this->close(); // Chiudi eventuali connessioni precedenti

        $this->socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            2, // timeout di connessione in secondi
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {

            $this->isConnected = false;
            $this->warn("Can not connect to Graylog ({$this->host}:{$this->port}): $errstr ($errno)");

        } else {

            stream_set_timeout($this->socket, 2); // timeout di scrittura/lettura
            $this->isConnected = true;

        }
    }

    public function append(LoggerLoggingEvent $event) {

        if (!$this->isConnected) {
            $this->connect();
            if (!$this->isConnected) {
                // Se ancora non connesso, scarta il log
                return;
            }
        }

        $fullMessage = $event->getRenderedMessage();
        $shortMessage = $fullMessage;

        if (strlen($shortMessage) > 250) {
            $shortMessage = substr($shortMessage, 0, 247) . '...';
        }

        $message = [
            'version'       => '1.1',
            'host'          => gethostname(),
            'short_message' => $shortMessage,
            'full_message'  => $fullMessage,
            'timestamp'     => microtime(true),
            'level'         => $this->mapLevel($event->getLevel()->toInt()),
            'facility'      => $this->facility,
            '_application'   => $this->application,
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

        // GELF TCP richiede un carattere null (\0) come terminatore di messaggio
        $json .= "\0";

        $bytesWritten = @fwrite($this->socket, $json);

        if ($bytesWritten === false || $bytesWritten < strlen($json)) {
            $this->warn("Error on send message GELF to Graylog. Retry to connect...");
            $this->connect();
        }
    }

    public function close() {

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->isConnected = false;
    }

    private function mapLevel($level) {

        switch ($level) {
            case LoggerLevel::FATAL: return 2; // Critical
            case LoggerLevel::ERROR: return 3; // Error
            case LoggerLevel::WARN:  return 4; // Warning
            case LoggerLevel::INFO:  return 6; // Informational
            case LoggerLevel::DEBUG: return 7; // Debug
            default:                 return 1; // Alert
        }
    }

    public function setHost($host) { $this->host = $host; }
    public function setPort($port) { $this->port = (int)$port; }
    public function setFacility($facility) { $this->facility = $facility; }
    public function setApplication($application) { $this->application = $application; }
    
}