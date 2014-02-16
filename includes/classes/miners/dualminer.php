<?php

/*
 *
 * @author Stoyvo
 */
class Class_Miners_Dualminer {

    protected $_host;
    protected $_port;
    protected $_summary;
//    protected $_stats;

    protected $_devs = array();
    protected $_pools = array();

    public function __construct($host, $port) {
        $this->_host = $host;
        $this->_port = $port;
        
        if (!extension_loaded('sockets')) {
            die('The sockets extension is not loaded.');
        }
    }

    private function getData($cmd) {
        $socket = $this->getSock($this->_host, $this->_port);
        if (empty($socket)) {
            return null;
        }
        
        // This will cause the page to load not load quickly if miner is offline. Need to handle this somehow.
        socket_write($socket, $cmd, strlen($cmd));
        $line = $this->readSockLine($socket);
        socket_close($socket);
        return $line;
    }

    private function getSock($addr, $port) {
        $socket = null;
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 2, 'usec' => 0));
        if ($socket === false || $socket === null) {
            return null;
        }
        
        if (!socket_connect($socket, $addr, $port)) {
            socket_close($socket);
            return null;
        }
        
        return $socket;
    }

    private function readSockLine($socket) {
        $line = '';
        while (true) {
            $byte = socket_read($socket, 1);
            if ($byte === false || $byte === '') {
                break;
            }
            if ($byte === "\0") {
                break;
            }
            $line .= $byte;
        }
        return $line;
    }
    
    private function getDevData ($devId) {
        $devId = intval($devId); // simple sanitizing
        $devData = $this->_devs[$devId];
        
        // Building Summary stats
        $this->_summary[0]['MHS 5s'] += (float) $devData['MHS 5s'];

        $data = array();

        $data = array(
            'id' => $devId,
            'enabled' => $devData['Enabled'],
            'health' => $devData['Status'],
            'hashrate_avg' => $devData['MHS av']  . ' MH/S',
            'hashrate_5s' => $devData['MHS 5s']  . ' MH/S',
            'accepted' => $devData['Accepted'],
            'rejected' => $devData['Rejected'],
            'hw_errors' => $devData['Hardware Errors'],
            'utility' => $devData['Utility'] . '/m',
        );
    
        return $data;
    }
    
    private function getSummaryData() {
        $summaryData = $this->_summary[0];
        $data = array();

        $activePool = null;
        $lastShareTime = null;
        foreach ($this->_pools as $pool) {
            $poolData = $pool;
            if (is_null($lastShareTime)) {
                $activePool = $poolData['Stratum URL'];
                $lastShareTime = $poolData['Last Share Time'];
            } else if ($lastShareTime < $poolData['Last Share Time']) {
                $activePool = $poolData['Stratum URL'];
                $lastShareTime = $poolData['Last Share Time'];                
            }
        }
        
        $data = array(
            'type' => 'dualminer',
            'uptime' => intval(($summaryData['Elapsed']) / 3600) . 'H ' . bcmod((intval(time() - $summaryData['Elapsed']) / 60),60) . 'M ' . bcmod((time() - $summaryData['Elapsed']),60) . 'S',
            'hashrate_avg' => $summaryData['MHS av'] . ' MH/s',
            'hashrate_5s' => $summaryData['MHS 5s'] . ' MH/s',
            'blocks_found' => $summaryData['Found Blocks'],
            'accepted' => $summaryData['Accepted'],
            'rejected' => $summaryData['Rejected'],
            'stale' => $summaryData['Stale'],
            'hw_errors' => $summaryData['Hardware Errors'],
            'utility' => $summaryData['Utility'] . '/m',
            'active_mining_pool' => $activePool,
        );
        
        return $data;
    }
    
    private function getAllData() {
        $data = array();
        
        // Get All Device data
        foreach ($this->_devs as $dev) {
            $data['devs'][] = $this->getDevData($dev['PGA']);
        }
        
        // Get GPU Summary
        $data['summary'] = $this->getSummaryData();
        
        return $data;
    }

    public function update() {
        // TODO:
        // - Determin if we need to update a json file or just do socket data returns. we dont have limitations on the amount of calls we make
        
        $summary = json_decode($this->getData('{"command":"summary"}'), true);
        $this->_summary = $summary['SUMMARY'];
        
        $dev = json_decode($this->getData('{"command":"devs"}'), true);
        $this->_devs = $dev['DEVS'];
        
        $pools = json_decode($this->getData('{"command":"pools"}'), true);
        $this->_pools = $pools['POOLS'];
        
        return $this->getAllData();
    }

}