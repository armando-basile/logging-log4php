<?php

/**
 * Appender to send log messages using Graylog with GELF TCP.
 */
class LoggerAppenderGelf extends LoggerAppender {

    protected $host = '127.0.0.1';
    protected $port = 12201;
    protected $facility = 'log4php';

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

        $message = [
            'version'       => '1.1',
            'host'          => gethostname(),
            'short_message' => 'Log ' . $this->mapLevel($event->getLevel()->toInt()),
            'full_message'  => $event->getRenderedMessage(),
            'timestamp'     => microtime(true),
            'level'         => $this->mapLevel($event->getLevel()->toInt()),
            'facility'      => $this->facility,
            '_logger'       => $event->getLoggerName(),
        ];
        
        $clientIp = LoggerMDC::get('client_ip');
        if ($clientIp) {
            $message['_client_ip'] = $clientIp;
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
    
}