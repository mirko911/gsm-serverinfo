<?php

/**
 * GSManager
 *
 * This is a mighty and platform independent software for administrating game servers of various kinds.
 * If you need help with installing or using this software, please visit our website at: www.gsmanager.de
 * If you have licensing enquiries e.g. related to commercial use, please contact us at: sales@gsmanager.de
 *
 * @copyright Greenfield Concept UG (haftungsbeschränkt)
 * @license GSManager EULA <https://www.gsmanager.de/eula.php>
 * @version 1.1.0
 * */

namespace GSM\Plugins\Serverinfo;

use GSM\Daemon\Core\Utils;

/**
 * Serverinfo Plugin
 *
 * Shows information about other Quake3 based Servers
 */
class Serverinfo extends Utils {

    private $servers = array();
    private $current = 0;
    private $job_id;

    /**
     * Inits the plugin
     *
     * This function initiates the plugin. This means that it register commands
     * default values, and events. It's important that every plugin has this function
     * Otherwise the plugin exists but can't be used
     */
    public function initPlugin() {
        parent::initPlugin();

        $this->config->setDefault('serverinfo', 'enabled', false);
        $this->config->setDefault('serverinfo', 'servers', []);
        $this->config->setDefault('serverinfo', 'message', "^1<IP> ^7<SERVERNAME> ^7 => Players: ^2<CURRENT_PLAYERS>/<MAX_PLAYERS> ^7 Map: ^2<MAPNAME> (<GAMETYPE>)");
        $this->config->setDefault('serverinfo', 'offline', "<IP> ^7is ^1OFFLINE");
        $this->config->setDefault('serverinfo', 'interval', 300);
    }

    /**
     * Enables this module.
     *
     * This function is called every time the plugin get enabled.
     *
     * In this function you should call functions like registerCommand, registerEvent, registerHook, addPeriodicJob, addEveryTimeJob, addCronJob.
     *
     * Never call this method on your own, only PluginLoader should do this to enable dependent plugins, too.
     * Use $this->pluginloader->enablePlugin($namespace) instead.
     */
    public function enable() {
        parent::enable();

        $this->events->register("parseConfig", [$this, "parseConfig"]);
        $this->events->register('gsmStarted',  [$this, 'sendMessage']);
        $this->parseConfig();
    }

    /**
     * Disables this module.
     *
     * In this function you should call functions like unregisterCommand, unregisterEvent, unregisterHook, deleteJob.
     *
     * Never call this method on your own, only PluginLoader should do this to disable dependent plugins, too.
     * Use $this->pluginloader->disablePlugin($namespace) instead.
     */
    public function disable() {
        parent::disable();
        
        $this->events->unregister("parseConfig", [$this, "parseConfig"]);
        $this->events->unregister('gsmStarted', [$this, 'sendMessage']);

        $this->jobs->deleteJob($this->job_id);
        $this->job_id = false;
    }

    /**
     *  Parses the config file of the plugin
     * */
    public function parseConfig() {
        $this->servers = array();
        foreach ($this->config->get('serverinfo', 'servers') as $server) {
            $server_parts = explode(":", $server);
            if (count($server_parts) != 2) {
                $this->logging->warning('Serverinfo: Invalid Server' . $server);
                continue;
            }
            if (filter_var($server_parts[0], FILTER_VALIDATE_IP)) {
                $this->servers[] = ["ip" => $server_parts[0], "port" => $server_parts[1]];
            }
        }
    }

    /**
     * Gets the relevant infos from server
     *
     * @param string $ip The server ip (IPv4)
     * @param int $port The server port
     * @return boolean|array false on fail|data on success
     */
    private function getInfo($ip, $port) {
        try {
            $q3query = new \GSM\Daemon\Engines\Quake3\Rcon\Rcon($ip, $port);
        } catch (Exception $ex) {
            return false;
        }

        $info = $q3query->getGameInfo();

        if (!$info) {
            $q3query->quit();
            return false;
        }

        $info["ping"] = $q3query->getLastPing();
        $info["hc"] = (isset($info["hc"])) ? $info["hc"] : 0;
        $info["kc"] = (isset($info["kc"])) ? $info["kc"] : 0;
        $info["ff"] = (isset($info["ff"])) ? $info["ff"] : 0;
        $info["od"] = (isset($info["od"])) ? $info["od"] : 0;
        $info["pb"] = (isset($info["pb"])) ? $info["pb"] : 0;
        $info["pure"] = (isset($info["pure"])) ? $info["pure"] : 0;
        $info["mod"] = (isset($info["mod"])) ? $info["mod"] : 0;
        $info["pswrd"] = (isset($info["pswrd"])) ? $info["pswrd"] : 0;
        $info["clients"] = (isset($info["clients"])) ? $info["clients"] : 0;
        $info["protocol"] = (isset($info["protocol"])) ? $info["protocol"] : "unknown";

        $q3query->quit();
        unset($q3query);

        return $info;
    }

    /**
     * Sends the message to server chat
     *
     * @return boolean false on fail
     */
    public function sendMessage() {
        if (empty($this->servers)) {
            return false;
        }

        if ($this->current >= count($this->servers)) {
            $this->current = 0;
        }

        $active_server = $this->servers[$this->current];

        $info = $this->getInfo($active_server["ip"], $active_server["port"]);

        if ($info === false) {
            $this->rcon->rconSay(str_replace("<IP>", $active_server["ip"] . ":" . $active_server["port"], $this->config->get('serverinfo', 'offline')));
            return;
        }

        $search[] = "<SERVERNAME>";
        $search[] = "<MAX_PLAYERS>";
        $search[] = "<CURRENT_PLAYERS>";
        $search[] = "<IP>";
        $search[] = "<MAPNAME>";
        $search[] = "<GAMETYPE>";
        $search[] = "<PING>";

        $replace[] = $info["hostname"];
        $replace[] = $info["sv_maxclients"];
        $replace[] = $info["clients"];
        $replace[] = $active_server["ip"] . ":" . $active_server["port"];
        $replace[] = $this->mod->getLongMapName($info["mapname"]);
        $replace[] = $this->mod->getLongGametype($info["gametype"]);
        $replace[] = $info["ping"];

        $msg = str_replace($search, $replace, $this->config->get("serverinfo", "message"));
        $this->rcon->rconsay($msg);

        $this->current++;
        $this->job_id = $this->jobs->addSingleJob($this->config->get("serverinfo", "interval"), [$this, "sendMessage"]);
    }

}
