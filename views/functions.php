<?php
declare(strict_types=1);
// This exact entry path is used by Liman's PHP Sandbox and Fiber Render Engine.
require_once dirname(__DIR__).'/app/bootstrap.php';

function index()
{
    try {
        \LockedShields\Policy::authorize('index');
        return view('index', [
            'manageTeams'=>can('ls_create_team'),
            'manageServices'=>can('ls_create_service'),
            'manageInjects'=>can('ls_add_inject'),
        ]);
    } catch (\Throwable $e) { return view('alert',['type'=>'danger','title'=>'Cenk','message'=>'Skor tahtasına erişim yetkiniz yok.']); }
}

function ls_dashboard() { return ls_dispatch('ls_dashboard'); }
// Compatibility name requested by deployment/smoke automation. Same read permission.
function getScoreboardData() { return ls_dispatch('ls_dashboard'); }
function ls_create_team() { return ls_dispatch('ls_create_team'); }
function ls_create_service() { return ls_dispatch('ls_create_service'); }
function ls_add_inject() { return ls_dispatch('ls_add_inject'); }

function ls_dispatch(string $action)
{
    try {
        $scope = \LockedShields\Policy::authorize($action);
        $config = \LockedShields\Config::load();
        $scoreboard = new \LockedShields\Scoreboard(\LockedShields\Database::connect($config));
        // Never accept server_id, user_id, role or timestamps from browser input.
        switch ($action) {
            case 'ls_dashboard': $result=$scoreboard->dashboard($scope); break;
            case 'ls_create_team': $result=$scoreboard->createTeam($scope,request()); break;
            case 'ls_create_service': $result=$scoreboard->createService($scope,request(),$config); break;
            case 'ls_add_inject': $result=$scoreboard->addInject($scope,(string)user()->id,request()); break;
            default: throw new \DomainException('Bilinmeyen işlem.',404);
        }
        return respond($result,200);
    } catch (\InvalidArgumentException | \JsonException $e) {
        return respond($e->getMessage(),422);
    } catch (\DomainException $e) {
        return respond($e->getMessage(),$e->getCode() ?: 403);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23505') { return respond('Bu isimde bir kayıt zaten var.',409); }
        error_log('LockedShields database failure SQLSTATE='.$e->getCode());
        return respond('Veritabanı işlemi tamamlanamadı. Yapılandırma ve migration durumunu kontrol edin.',503);
    } catch (\Throwable $e) {
        error_log('LockedShields failure: '.get_class($e));
        return respond('Scoreboard hazır değil. Kurulum yapılandırmasını kontrol edin.',503);
    }
}
