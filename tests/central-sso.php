<?php
/** Loopback HTTP tests using real app bootstraps/authentication and fixture SQL/connector. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$options = getopt('', ['connector-root:']);
$connectorRoot = $options['connector-root'] ?? null;
$fixture = sys_get_temp_dir() . '/warehouse-sso-test-' . bin2hex(random_bytes(6));
$app = $fixture . '/htdocs/WarehouseIssuance';
$server = null;
$checks = 0;
$failed = false;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function put(string $path, string $body): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $body);
}
function removeFixture(string $path): void {
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) removeFixture($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($path);
}
final class FixtureBrowser {
    public array $cookies = [];
    public function __construct(private string $origin) {}
    public function request(string $path, ?array $data = null, bool $https = true, array $extraHeaders = []): array {
        if (!str_starts_with($path, '/')) $path = '/WarehouseIssuance/' . $path;
        $headers = array_merge(['Connection: close'], $extraHeaders);
        if ($https) $headers[] = 'X-Fixture-HTTPS: on';
        if ($this->cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : http_build_query($data),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = @file_get_contents($this->origin . $path, false, $context);
        if ($body === false) throw new RuntimeException('Fixture HTTP server unavailable: ' . $path);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        $response = ['status' => (int)$match[1], 'body' => $body, 'location' => '', 'headers' => $http_response_header];
        foreach ($http_response_header as $header) {
            if (stripos($header, 'Location: ') === 0) $response['location'] = substr($header, 10);
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $cookie)) {
                if ($cookie[2] === '' || $cookie[2] === 'deleted') unset($this->cookies[$cookie[1]]); else $this->cookies[$cookie[1]] = $cookie[2];
            }
        }
        return $response;
    }
}

try {
    foreach (['includes/auth.php','includes/central-auth.php','central-session.php','central-sso.php','index.php','pages/auth/login.php','pages/auth/logout.php','api/tablet_print_capabilities.php'] as $file) put($app.'/'.$file,file_get_contents($root.'/'.$file));
    // No original config.php, SQL connection, SAP client or printer is loaded.
    put($fixture.'/database.php', <<<'PHP'
<?php
function fixtureState():array{return json_decode(file_get_contents(getenv('WH_SSO_FIXTURE').'/state.json'),true);}
function get_whpokayoke_connection($throwOnFailure=false){if(fixtureState()['db_outage'])throw new RuntimeException('Fixture database outage.');return 'fixture-connection';}
function fetch_one($conn,$sql,$params=[]){
    $s=fixtureState();
    if(!str_contains($sql,'FROM AppUsers')||($params[0]??null)!==$s['username'])throw new RuntimeException('Unexpected fixture query.');
    if(!$s['account'])return null;
    return ['UserID'=>42,'Username'=>$s['username'],'FullName'=>'Fixture Staff','RoleName'=>$s['role'],'ReceiverArea'=>'Kitting',
        'DeviceHostname'=>'fixture-device','DeviceIPAddress'=>'192.0.2.20','PasswordHash'=>password_hash('fixture-password',PASSWORD_DEFAULT)];
}
function sqlsrv_query($conn,$sql,$params=[]){
    if(!str_starts_with($sql,'UPDATE AppUsers')||!str_contains($sql,'LastLoginAt'))throw new RuntimeException('Unexpected fixture write.');
    file_put_contents(getenv('WH_SSO_FIXTURE').'/login-metadata.jsonl',json_encode($params)."\n",FILE_APPEND);return true;
}
PHP);
    put($app.'/includes/db_connect.php',"<?php require_once getenv('WH_SSO_FIXTURE').'/database.php'; define('APP_BASE_URL','https://warehouse.invalid/WarehouseIssuance');");
    put($fixture.'/output.php', <<<'PHP'
<?php
function fixtureOutput(){echo json_encode(['name'=>session_name(),'state'=>$_SESSION,'logout'=>whAuthUrl(app_path('pages/auth/logout.php')),
    'asset'=>whAuthUrl(app_path('assets/app.js')),'qr'=>app_url('pages/receiver/receiver.php?trace_no=fixture'),
    'link'=>whAuthUrl(app_path('index.php')),'field'=>whAuthField()]);}
PHP);
    $guards=[
        'pages/issuer/issuer.php'=>'require_role([ROLE_ISSUER, ROLE_ADMIN]);',
        'pages/picker/picker.php'=>'require_role([ROLE_PICKER, ROLE_ADMIN]);',
        'pages/requestor/requestor.php'=>'require_role([ROLE_REQUESTOR, ROLE_ADMIN]);',
        'pages/dashboard/verification_dashboard.php'=>'require_login();',
        'pages/receiver/receiver.php'=>'require_role([ROLE_RECEIVER, ROLE_ADMIN]);',
        'pages/admin/admin_users.php'=>'require_role(ROLE_ADMIN);',
        'pages/sap_encoder/report.php'=>'require_role([ROLE_SAP_ENCODER, ROLE_ADMIN]);',
        'api/scan_receive_item.php'=>'require_role([ROLE_RECEIVER, ROLE_ADMIN]);',
        'actions/user_save.php'=>'require_role(ROLE_ADMIN);',
    ];
    foreach($guards as $file=>$guard){
        $native=file_get_contents($root.'/'.$file);
        check(str_contains(preg_replace('/\s+/','',$native),preg_replace('/\s+/','',$guard)),'Fixture uses actual native guard: '.$file);
        $depth=substr_count($file,'/');$prefix=str_repeat('../',$depth);
        put($app.'/'.$file,"<?php require_once __DIR__.'/".$prefix."includes/auth.php';\n".$guard."\nrequire_once getenv('WH_SSO_FIXTURE').'/output.php';fixtureOutput();");
    }
    put($app.'/api/get_part_name.php',"<?php echo 'public-fixture';");
    mkdir($fixture.'/sessions',0700,true);put($fixture.'/bridge/runtime.php',file_get_contents(__DIR__.'/fixtures/central-runtime.php'));
    if(is_string($connectorRoot)){
        $functions=rtrim($connectorRoot,'/\\').'/functions.php';if(!is_file($functions))throw new RuntimeException('Connector functions.php not found.');
        put($fixture.'/bridge/functions.php',file_get_contents($functions));put($fixture.'/bridge/curl.php',file_get_contents(__DIR__.'/fixtures/central-curl.php'));
        put($fixture.'/bridge/runtime.php', <<<'PHP'
<?php
namespace NbcCentral;
require_once __DIR__.'/functions.php';require_once __DIR__.'/curl.php';
$GLOBALS['nbc_config']=bridgeConfiguration(['issuer'=>'https://central.invalid:4883/nbc-central','htdocs'=>dirname(__DIR__).'/htdocs',
    'applications'=>['warehouse'=>['folder'=>'WarehouseIssuance','cookie_path'=>'/WarehouseIssuance','key'=>str_repeat('c',64),
    'callback'=>'https://'.$_SERVER['HTTP_HOST'].'/WarehouseIssuance/central-sso.php']]]);
PHP);
    }
    put($fixture.'/central/app/runtime.php',file_get_contents(__DIR__.'/fixtures/central-runtime.php'). <<<'PHP'

function consumeTicket(string $ticket,string $app,string $binding):array{
    $state=fixtureState();
    if($app!=='warehouse'||$binding!=='fixture-binding'||$ticket!=='valid-ticket'||!empty($state['ticket_used']))throw new \RuntimeException('Invalid local ticket.');
    $state['ticket_used']=true;file_put_contents(getenv('WH_SSO_FIXTURE').'/state.json',json_encode($state));
    return ['user_id'=>7,'local_username'=>$state['username'],'session_hash'=>'fixture-session-hash'];
}
PHP);
    put($fixture.'/central/integrations/session.php','<?php');
    $state=['role'=>'admin','username'=>'0000','account'=>true,'grant'=>true,'outage'=>false,'db_outage'=>false,'prepend'=>false];
    $save=static function()use(&$state,$fixture):void{file_put_contents($fixture.'/state.json',json_encode($state));};$save();
    put($fixture.'/router.php', <<<'PHP'
<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$file=__DIR__.'/htdocs'.$path;if(!is_file($file)){http_response_code(404);exit;}
$_SERVER['SCRIPT_FILENAME']=$file;$_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']=$path;
if(($_SERVER['HTTP_X_FIXTURE_HTTPS']??'')==='on')$_SERVER['HTTPS']='on';
$state=json_decode(file_get_contents(__DIR__.'/state.json'),true);
if(!empty($state['local'])){putenv('WH_REMOTE_ROOT=');putenv('WH_CENTRAL_ROOT='.__DIR__.'/central');}
if(!empty($state['prepend']))require_once __DIR__.'/htdocs/WarehouseIssuance/central-session.php';
chdir(dirname($file));require $file;
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve fixture port.');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['WH_REMOTE_ROOT']=$fixture.'/bridge';$env['WH_CENTRAL_ROOT']=$fixture.'/missing-central';$env['WH_SSO_FIXTURE']=$fixture;$env['HTTP_PROXY']=$env['http_proxy']='';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-d','session.gc_probability=0','-d','display_errors=0',
        '-d','disable_functions=sqlsrv_query','-S',$address,$fixture.'/router.php'],
        [0=>['pipe','r'],1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes,$fixture,$env,['bypass_shell'=>true,'create_no_window'=>true]);
    if(!is_resource($server))throw new RuntimeException('Cannot start fixture server.');fclose($pipes[0]);
    $browser=new FixtureBrowser('http://'.$address);$base='/WarehouseIssuance/';
    for($i=0;$i<60;++$i){try{$browser->request($base.'pages/auth/login.php');break;}catch(RuntimeException){usleep(50000);}}
    $launch=static function(FixtureBrowser $client)use($base,$fixture):array{
        $start=$client->request($base.'central-sso.php');check($start['status']===303,'Central GET begins authorization.');
        parse_str((string)parse_url($start['location'],PHP_URL_QUERY),$query);
        check($query['client_id']==='warehouse'&&$query['code_challenge_method']==='S256','Correct Warehouse client and PKCE.');
        $issued=json_decode(file_get_contents($fixture.'/state.json'),true);$issued['expected_challenge']=$query['code_challenge'];file_put_contents($fixture.'/state.json',json_encode($issued));
        $callback=$base.'central-sso.php?'.http_build_query(['state'=>$query['state'],'code'=>str_repeat('b',64),'iss'=>'https://central.invalid:4883/nbc-central']);
        return [$client->request($callback),$callback];
    };
    $nativeFile=static fn(FixtureBrowser $client):string=>$fixture.'/sessions/sess_'.$client->cookies['PHPSESSID'];
    $login=static fn(FixtureBrowser $client,string $password='fixture-password'):array=>$client->request($base.'pages/auth/login.php',['username'=>'0000','password'=>$password]);
    $before=file_get_contents($nativeFile($browser));$nativeId=$browser->cookies['PHPSESSID'];[$handoff]=$launch($browser);
    check($handoff['status']===303&&str_contains($handoff['location'],'index.php?wh_auth=central'),'SSO enters existing role dispatcher.');
    check($browser->cookies['PHPSESSID']===$nativeId&&file_get_contents($nativeFile($browser))===$before,'SSO preserves default native cookie and session bytes.');
    $dispatch=$browser->request($handoff['location']);check($dispatch['status']===302&&str_contains($dispatch['location'],'verification_dashboard.php?wh_auth=central'),'Admin role redirect retains context.');
    $central=json_decode($browser->request($dispatch['location'])['body'],true);
    check($central['name']==='WH_STAFF_SSO'&&$central['state']['user']['username']==='0000','Isolated SSO and leading-zero username.');
    check($central['state']['user']['receiver_area']==='Kitting'&&$central['state']['user']['device_hostname']==='fixture-device'&&$central['state']['user']['device_ip']==='192.0.2.20','Receiver and assigned-device fields preserved.');
    check($central['asset']===$base.'assets/app.js'&&!str_contains($central['qr'],'wh_auth'),'Assets and printed QR URL unchanged.');
    check(str_contains($login($browser,'wrong')['body'],'Invalid username or password'),'Native password enforced.');check($login($browser)['status']===302,'Native login coexists with SSO.');
    $native=json_decode($browser->request($base.'pages/dashboard/verification_dashboard.php?wh_auth=native')['body'],true);
    check($native['name']==='PHPSESSID','Production default native session name retained.');$loggedIn=file_get_contents($nativeFile($browser));
    $logout=$browser->request($central['logout']);check($logout['status']===303&&$logout['location']==='https://central.invalid:4883/nbc-central/index.php','Central logout returns to trusted portal.');
    check(file_get_contents($nativeFile($browser))===$loggedIn,'Central logout preserves native login.');
    [$r]=$launch($browser);check(str_contains($browser->request($native['logout'])['location'],'pages/auth/login.php?wh_auth=native'),'Native logout retains native destination.');
    check($browser->request($base.'pages/dashboard/verification_dashboard.php?wh_auth=central')['status']===200,'Native logout preserves SSO.');
    $browser->cookies['WH_AUTH_MODE']='native';
    $cap=$base.'api/tablet_print_capabilities.php';
    $referer='Referer: https://'.$address.$base.'pages/picker/picker.php?wh_auth=central';
    check($browser->request($cap,null,true,[$referer])['status']===200,'Older tablet capability request retains page context through same-origin referrer.');
    check($browser->request($cap)['status']===302,'Bare native capability request still requires native sign-in.');
    check($browser->request($cap.'?wh_auth=native',null,true,[$referer])['status']===302,'Explicit native selection takes priority over referrer.');
    check($browser->request($cap,null,true,['Referer: https://other.invalid/WarehouseIssuance/pages/picker/picker.php?wh_auth=central'])['status']===302,'Foreign referrer cannot select a session context.');
    check($browser->request($cap,null,true,['Referer: https://'.$address.'/OtherApp/index.php?wh_auth=central'])['status']===302,'Other app referrer cannot select Warehouse context.');
    $browser->cookies['WH_AUTH_MODE']='central';
    // Preserve an authenticated legacy cookie/store from the earlier optional bridge.
    $legacy=new FixtureBrowser('http://'.$address);$legacyId='legacy-existing-warehouse';$legacy->cookies['NBC_NATIVE_WAREHOUSE']=$legacyId;
    mkdir($fixture.'/sessions/nbc-warehouse-native-sessions',0700,true);$legacyFile=$fixture.'/sessions/nbc-warehouse-native-sessions/sess_'.$legacyId;file_put_contents($legacyFile,$loggedIn);
    $resumed=json_decode($legacy->request($base.'pages/dashboard/verification_dashboard.php?wh_auth=native')['body'],true);
    check($resumed['name']==='NBC_NATIVE_WAREHOUSE'&&$legacy->cookies['NBC_NATIVE_WAREHOUSE']===$legacyId,'Existing legacy native session resumes in original storage.');
    $legacyBytes=file_get_contents($legacyFile);[$r]=$launch($legacy);check(file_get_contents($legacyFile)===$legacyBytes,'SSO preserves existing legacy native session bytes.');
    check($legacy->request($central['logout'])['status']===303&&file_get_contents($legacyFile)===$legacyBytes,'SSO logout preserves legacy native session.');
    $landings=['issuer'=>'pages/issuer/issuer.php','picker'=>'pages/picker/picker.php','warehouse'=>'pages/picker/picker.php','requestor'=>'pages/requestor/requestor.php',
        'receiver'=>'pages/dashboard/verification_dashboard.php','sap_encoder'=>'pages/sap_encoder/report.php','admin'=>'pages/dashboard/verification_dashboard.php'];
    foreach($landings as $role=>$landing){
        $state['role']=$role;$state['prepend']=!$state['prepend'];$save();[$r]=$launch($browser);$route=$browser->request($r['location']);
        check(str_contains($route['location'],$landing)&&str_contains($route['location'],'wh_auth=central'),$role.' native landing with/without prepend.');
        $account=json_decode($browser->request($route['location'])['body'],true);check($account['state']['user']['role']===$role,$role.' native role retained.');
        check($browser->request($base.'pages/admin/admin_users.php?wh_auth=central')['status']===($role==='admin'?200:403),$role.' user-management restriction.');
        check($browser->request($base.'pages/picker/picker.php?wh_auth=central')['status']===(in_array($role,['picker','warehouse','admin'],true)?200:403),$role.' picker restriction.');
        check($browser->request($base.'pages/issuer/issuer.php?wh_auth=central')['status']===(in_array($role,['issuer','warehouse','admin'],true)?200:403),$role.' issuer restriction.');
        check($browser->request($base.'api/scan_receive_item.php?wh_auth=central')['status']===(in_array($role,['receiver','admin'],true)?200:403),$role.' receiving API restriction.');
    }
    $state['role']='requestor';$save();check($browser->request($base.'actions/user_save.php?wh_auth=central',[])['status']===403,'Role downgrade applies before an admin write.');
    $state['role']='admin';$save();[$r,$used]=$launch($browser);$other=new FixtureBrowser('http://'.$address);
    check($other->request($used)['status']===403,'Browser-bound callback.');check($browser->request($used)['status']===403,'State replay rejected.');
    [$r]=$launch($browser);$state['grant']=false;$save();check($browser->request($base.'index.php?wh_auth=central')['status']===403,'Revoked grant rejected.');
    $state['grant']=true;$save();[$r]=$launch($browser);$state['account']=false;$save();check($browser->request($base.'index.php?wh_auth=central')['status']===403,'Inactive mapped account rejected.');
    check(str_contains($login($other)['body'],'Invalid username or password'),'Inactive native account rejected.');
    $state['account']=true;$save();[$r]=$launch($browser);$state['db_outage']=true;$save();
    check($browser->request($base.'index.php?wh_auth=central')['status']===503,'Warehouse SQL outage returns bounded SSO failure.');
    $state['db_outage']=false;$save();[$r]=$launch($browser);$state['outage']=true;$save();
    check($browser->request($central['logout'])['location']==='https://central.invalid:4883/nbc-central/index.php','SSO logout works during Central outage.');
    check($login($browser)['status']===302,'Native password works during Central outage.');
    check($browser->request($base.'pages/dashboard/verification_dashboard.php?wh_auth=native')['status']===200,'Native protected page works during Central outage.');
    $state['outage']=false;$save();[$r]=$launch($browser);$state['outage']=true;$save();
    $failure=$browser->request($base.'api/scan_receive_item.php?wh_auth=central');check($failure['status']===503&&json_decode($failure['body'],true)['ok']===false,'SSO API outage returns JSON without a login loop.');
    check($browser->request($base.'api/get_part_name.php')['body']==='public-fixture','Public helper remains independent of Central.');
    check($other->request($base.'central-sso.php?code='.str_repeat('b',64),null,false)['status']===403,'HTTP authorization response rejected.');
    check($other->request($base.'central-sso.php',null,false)['status']===303,'Initial credential-free HTTP launch upgrades HTTPS.');
    check($other->request($base.'central-sso.php',['ticket'=>'wrong'])['status']===405,'Remote callback rejects POST ticket.');
    $state['outage']=false;$save();[$r]=$launch($browser);put($app.'/.warehouse-sso-disabled','disabled');
    check($browser->request($base.'index.php?wh_auth=central')['status']===503,'App-owned SSO switch blocks Central.');
    check($browser->request($base.'pages/dashboard/verification_dashboard.php?wh_auth=native')['status']===200,'App-owned switch preserves native login.');unlink($app.'/.warehouse-sso-disabled');
    put($app.'/.nbc-central-disabled','Legacy installers must skip this app.');[$r]=$launch($browser);check($r['status']===303,'Legacy installer opt-out does not disable app-owned optional SSO.');
    $state['local']=true;$save();$browser->cookies['NBC_SSO_BIND']='fixture-binding';$local=$browser->request($base.'central-sso.php',['ticket'=>'valid-ticket']);
    check($local['status']===303,'Same-host ticket handoff.');check($browser->request($local['location'])['status']===302,'Same-host grant validation.');
    check($browser->request($central['logout'])['location']==='/nbc-central/index.php','Same-host logout portal destination.');
    check($browser->request($base.'central-sso.php',['ticket'=>'valid-ticket'])['status']===403,'Local ticket replay rejected.');
    check(count(file($fixture.'/login-metadata.jsonl'))>7,'Native and SSO logins update native last-login metadata only.');
    echo 'Warehouse SSO: '.$checks." checks passed (fixture SQL/connector; no live SQL, SAP or printing).\n";
}catch(Throwable $error){
    fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");if(is_file($fixture.'/server.log'))fwrite(STDERR,implode('',array_slice(file($fixture.'/server.log'),-18)));$failed=true;
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}removeFixture($fixture);
}
exit($failed?1:0);

