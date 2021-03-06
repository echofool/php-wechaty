<?php
/**
 * Created by PhpStorm.
 * User: peterzhang
 * Date: 2020/7/10
 * Time: 5:39 PM
 */
namespace IO\Github\Wechaty\PuppetHostie;

use IO\Github\Wechaty\Puppet\Puppet;
use IO\Github\Wechaty\Puppet\Schemas\Event\EventScanPayload;
use IO\Github\Wechaty\Puppet\Schemas\EventEnum;
use IO\Github\Wechaty\Puppet\Schemas\FriendshipPayload;
use IO\Github\Wechaty\Puppet\Schemas\PuppetOptions;
use IO\Github\Wechaty\Puppet\StateEnum;
use IO\Github\Wechaty\PuppetHostie\Exceptions\PuppetHostieException;
use IO\Github\Wechaty\Util\Console;
use IO\Github\Wechaty\Util\Logger;
use Wechaty\Puppet\EventResponse;
use Wechaty\Puppet\EventType;

class PuppetHostie extends Puppet {
    private $_channel = null;
    /**
     * @var null|\Wechaty\PuppetClient
     */
    private $_grpcClient = null;

    const CHATIE_ENDPOINT = "https://api.chatie.io/v0/hosties/";

    public static function get() {

    }

    public function start() {
        if(self::$_STATE == StateEnum::ON) {
            Logger::WARNING("start() is called on a ON puppet. await ready(on) and return.");
            self::$_STATE = StateEnum::ON;
            return true;
        }
        self::$_STATE = StateEnum::PENDING;

        try {
            $this->_startGrpcClient();

            $startRequest = new \Wechaty\Puppet\StartRequest();
            $this->_grpcClient->Start($startRequest);

            $this->_startGrpcStream();
            self::$_STATE = StateEnum::ON;
        } catch (\Exception $e) {
            Logger::ERR("start() rejection:", $e);
            self::$_STATE = StateEnum::OFF;
        }

        return true;
    }

    public function stop() {
        Logger::DEBUG("stop()");
        if (self::$_STATE == StateEnum::OFF) {
            Logger::WARNING("stop() is called on a OFF puppet. await ready(off) and return.");
            return true;
        }

        try {
            if ($this->logonoff()) {
                $this->emit(EventEnum::LOGOUT, $this->_getId(), "logout");

                $this->setId(null);
            }

            if (!empty($this->_grpcClient)) {
                try {
                    $stopRequest = new \Wechaty\Puppet\StopRequest();
                    $this->_grpcClient->Stop($stopRequest);
                } catch (\Exception $e) {
                    Logger::ERR("stop() this._grpcClient.stop() rejection:", $e);
                }
            } else {
                Logger::WARNING("stop() this._grpcClient not exist");
            }
            $this->_stopGrpcClient();

        } catch (\Exception $e) {
            Logger::WARNING("stop() rejection: ", $e);
        }
        self::$_STATE = StateEnum::OFF;
    }

    function friendshipRawPayload($friendshipId) {
        $startRequest = new \Wechaty\Puppet\FriendshipPayloadRequest();
        $startRequest->setId($friendshipId);

        list($response, $status) = $this->_grpcClient->FriendshipPayload($startRequest)->wait();
        $payload = new FriendshipPayload();

        $payload->scene = $response->getScene();
        $payload->stranger = $response->getStranger();
        $payload->ticket = $response->getTicket();
        $payload->type = $response->getType();
        $payload->contactId = $response->getContactId();
        $payload->id = $response->getId();

        return $payload;
    }

    private function _startGrpcClient() {
        $endPoint = $this->_puppetOptions ? $this->_puppetOptions->endPoint : "";
        $discoverHostieIp = array();
        if(empty($endPoint)) {
            $discoverHostieIp = $this->_discoverHostieIp();
        } else {
            $split = explode(":", $endPoint);
            if (sizeof($split) == 1) {
                $discoverHostieIp[0] = $split[0];
                $discoverHostieIp[1] = "8788";
            } else {
                $discoverHostieIp = $split;
            }
        }

        if (empty($discoverHostieIp[0]) || $discoverHostieIp[0] == "0.0.0.0") {
            Logger::ERR("cannot get ip by token, check token");
            exit;
        }
        $hostname = $discoverHostieIp[0] . ":" . $discoverHostieIp[1];

        $this->_grpcClient = new \Wechaty\PuppetClient($hostname, [
            'credentials' => \Grpc\ChannelCredentials::createInsecure()
        ]);
        return $this->_grpcClient;
    }

    private function _stopGrpcClient() {
        Logger::DEBUG("grpc is shutdown");
        return true;
    }

    private function _startGrpcStream() {
        $eventRequest = new \Wechaty\Puppet\EventRequest();
        $call = $this->_grpcClient->Event($eventRequest);
        $ret = $call->responses();//Generator Object
        while($ret->valid()) {
            // Console::logStr($ret->key() . " ");//0 1 2
            $response = $ret->current();
            $this->_onGrpcStreamEvent($response);
            $ret->next();
        }
        echo "service stopped normally\n";
        Console::log($ret->getReturn());
    }

    private function _discoverHostieIp() : array {
        $url = self::CHATIE_ENDPOINT . $this->_puppetOptions->token;
        $client = new \GuzzleHttp\Client();

        $response = $client->request('GET', $url);

        $ret = array();
        if($response->getStatusCode() == 200) {
            Logger::DEBUG("$url with response " . $response->getBody());
            $ret = json_decode($response->getBody(), true);
            if(json_last_error()) {
                Logger::ERR("_discoverHostieIp json_decode with error " . json_last_error_msg());
                throw new PuppetHostieException("_discoverHostieIp json_decode with error " . json_last_error_msg());
            }
            return array($ret["ip"], $ret["port"]);
        } else {
            Logger::ERR("_discoverHostieIp request error with not 200, code is " . $response->getStatusCode());
        }

        return $ret;
    }

    private function _onGrpcStreamEvent(EventResponse $event) {
        try {
            $type = $event->getType();
            $payload = $event->getPayload();

            Logger::DEBUG("PuppetHostie $type payload $payload");

            switch ($type) {
                case EventType::EVENT_TYPE_SCAN:
                    $eventScanPayload = new EventScanPayload($payload);
                    Logger::DEBUG("scan event", array("payload" => $eventScanPayload));
                    $this->emit(EventEnum::SCAN, $eventScanPayload);
                    break;
                case EventType::EVENT_TYPE_HEARTBEAT:
                    // array is easy
                    $this->emit(EventEnum::HEART_BEAT, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_DONG:
                    $this->emit(EventEnum::DONG, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_ERROR:
                    $this->emit(EventEnum::ERROR, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_FRIENDSHIP:
                    $this->emit(EventEnum::FRIENDSHIP, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_LOGIN:
                    $payload = json_decode($payload, true);
                    $this->setId($payload["contactId"]);
                    $this->emit(EventEnum::LOGIN, $payload);
                    break;
                case EventType::EVENT_TYPE_LOGOUT:
                    $this->setId("");
                    $this->emit(EventEnum::LOGOUT, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_MESSAGE:
                    $this->emit(EventEnum::MESSAGE, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_READY:
                    $this->emit(EventEnum::READY, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_ROOM_INVITE:
                    $this->emit(EventEnum::ROOM_INVITE, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_ROOM_JOIN:
                    $this->emit(EventEnum::ROOM_JOIN, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_ROOM_LEAVE:
                    $this->emit(EventEnum::ROOM_LEAVE, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_ROOM_TOPIC:
                    $this->emit(EventEnum::ROOM_TOPIC, json_decode($payload, true));
                    break;
                case EventType::EVENT_TYPE_RESET:
                    break;
                case EventType::EVENT_TYPE_UNSPECIFIED:
                    break;
                default:
                    Console::logStr($event->getType() . " ");//2
                    Console::logStr($event->getPayload() . " ");
                    //{"qrcode":"https://login.weixin.qq.com/l/IaysbZa04Q==","status":5}
                    //{"data":"heartbeat@browserbridge ding","timeout":60000}
                    //$client->DingSimple($dingRequest);
                    //3{"data":"dong"}
                    echo "\n";
            }
        } catch (\Exception $e) {
            Logger::ERR("_onGrpcStreamEvent error", $e);
        }
    }
}