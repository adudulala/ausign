<?php
require_once __DIR__.'/vendor/autoload.php';
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use think\facade\Db;
use Workerman\Worker;
use Workerman\Lib\Timer;

Db::setConfig([
    'default'     => 'mysql',
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type'     => 'mysql',
            // 主机地址
            'hostname' => '127.0.0.1',
            // 用户名
            'username' => 'k4h_cc',
            'password' => 'erA5w72N8jdsKbxJ',
            // 数据库名
            'database' => 'k4h_cc',
            // 数据库编码默认采用utf8
            'charset'  => 'utf8',
            // 数据库表前缀
            'prefix'   => '',
            // 数据库调试模式
            'debug'    => true,
        ],
    ],
]);

function plist_write($path,$ipaurl,$bundleId,$name,$logo){
    $html = '<?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
    <plist version="1.0">
    <dict>
        <key>items</key>
        <array>
            <dict>
                <key>assets</key>
                <array>
                    <dict>
                        <key>kind</key>
                        <string>software-package</string>
                        <key>url</key>
                        <string>'.$ipaurl.'</string>
                    </dict>
                    <dict>
                        <key>kind</key>
                        <string>display-image</string>
                        <key>url</key>
                        <string>'.$logo.'</string>
                    </dict>
                    <dict>
                        <key>kind</key>
                        <string>full-size-image</string>
                        <key>url</key>
                        <string>'.$logo.'</string>
                    </dict>
                </array>
                <key>metadata</key>
                <dict>
                    <key>bundle-identifier</key>
                    <string>'.$bundleId.'</string>
                    <key>bundle-version</key>
                    <string>1.0.0</string>
                    <key>kind</key>
                    <string>software</string>
                    <key>title</key>
                    <string>'.$name.'</string>
                </dict>
            </dict>
        </array>
    </dict>
    </plist>';
    $plistFile = fopen($path, "w");
    fwrite($plistFile, $html);
    fclose($plistFile);
}

function get_downloadurl($path){
    return 'https://xb1.pw/static/target/'.$path;
}

function apple_log($info){
	file_put_contents(__DIR__.'/log.txt', $info."\t".date('Y-m-d H:i:s')."\n", FILE_APPEND);
}

function app_signer(){
	$item = Db::name('spDevice')->where('isok',0)->find();
	var_dump($item);
	if ($item) {
		Db::name('spDevice')->where('id',$item['id'])->update(['isok'=>1]);

		$app_item = Db::name('spApp')->where('id',$item['app_id'])->find();
		$app_id = $app_item['id'];
		$UDID = $item['udid'];
		$target_path = '/www/wwwroot/xb1.pw/static/target/';
		$ausign_path = '/www/wwwroot/xb1.pw/static/ausign/';
		$mobileprovision_path = '/www/wwwroot/xb1.pw/static/mobileprovision/';
		$ipaName = $app_item['ipa_fullpath'];

		$device_acc_item = Db::name('spDeviceAcc')->where('udid',$item['udid'])->find();
		if ($device_acc_item) {
			if ($device_acc_item['device_mobileprovision'] == '') {
				// 没有描述文件生成描述文件签名
				$acc_item = Db::name('spAcc')->where('id',$device_acc_item['acc_id'])->find();
				

				$bundleId_id = $acc_item['bundle_id'];
				$certificate_id = $acc_item['cert_id'];
				$p12 = $acc_item['p12'];
				$p12pw = $acc_item['p12pw'];
				

				$randnum = time().rand(100000,999999);
				// 生成JWT凭据
				$p8 = file_get_contents($acc_item['p8']);
				$key = $p8;
				$signer = new Sha256();
				$time = time();
				$token = new Builder();
				$token->setHeader('typ','JWT');
				$token->setHeader('kid',$acc_item['key_id']);
				$token->setHeader('alg','ES256');
				$token->set('iss',$acc_item['issuser_id']);
				$token->set('exp',time()+600);
				$token->set('aud','appstoreconnect-v1');
				$jwt = (string)$token->getToken($signer, new Key($key));
				
				// 注册设备UDID
				exec("curl -g -X POST -H 'Content-Type: application/json' -H 'Authorization: Bearer $jwt' -d '{
					\"data\": {
						\"attributes\": {
								\"name\": \"$randnum\",
								\"udid\": \"$UDID\",
								\"platform\": \"IOS\"
						},
						\"type\": \"devices\"
					}
				}' https://api.appstoreconnect.apple.com/v1/devices",$result,$errno);
				apple_log(json_encode($result));
				// 获取设备文件id
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
				curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
				curl_setopt($ch, CURLOPT_URL, "https://api.appstoreconnect.apple.com/v1/devices?filter[udid]=$UDID");
				curl_setopt($ch, CURLOPT_PORT, 443);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer ".$jwt));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);
				$result = curl_exec($ch);
				curl_close($ch);
				$json = json_decode($result,true);
				apple_log($json['data'][0]['id']);
				$device_id = $json['data'][0]['id'];

				// 生成设备描述文件
				exec("curl -H 'Authorization: Bearer $jwt' -H 'Content-type:application/json' -X POST -d '{
				\"data\": {
					\"attributes\":{
				  	\"name\":\"$randnum\",
				  	\"profileType\":\"IOS_APP_ADHOC\"
					},
					\"relationships\":{
				    	\"bundleId\": {
				        	\"data\": {
				            	\"id\":\"$bundleId_id\",
				            	\"type\":\"bundleIds\"
				        	}
				    	},
				    	\"certificates\": {
				        	\"data\": [{
				            	\"id\":\"$certificate_id\",
				            	\"type\":\"certificates\"
				        	}]
				    	},
				    	\"devices\":{
				        	\"data\": [{
				            	\"id\":\"$device_id\",
				            	\"type\":\"devices\"
				        	}]
				    	}
					},
					\"type\":\"profiles\"
				}
				}' https://api.appstoreconnect.apple.com/v1/profiles 2>&1",$output,$result);
				// 下载描述文件
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
				curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
				curl_setopt($ch, CURLOPT_URL, "https://api.appstoreconnect.apple.com/v1/profiles?filter[profileType]=IOS_APP_ADHOC&filter[name]=$randnum");//query：profile名字和刚创建的一样
				curl_setopt($ch, CURLOPT_PORT, 443);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer ".$jwt));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);
				$result = curl_exec($ch);
				curl_close($ch);
				$json = json_decode($result,true);
				$profile = $mobileprovision_path.$UDID.'.mobileprovision';
				file_put_contents($profile, base64_decode($json['data'][0]['attributes']['profileContent']));
				Db::name('spDeviceAcc')->where('udid',$item['udid'])->update(['device_mobileprovision'=>$profile]);

				// 签名生成安装包
				exec($ausign_path.'ausign --email 739218667@qq.com -p b123456', $output, $result1);
				
				apple_log($ausign_path.'ausign --email 739218667@qq.com -p b123456');
				apple_log(json_encode($output));
				

				exec($ausign_path.'ausign --sign '.$ipaName.' -c '.$p12.' -m '.$profile.' -p '.$p12pw.' -o '.$target_path.$UDID.'-'.$app_id.'.ipa -dt', $output, $result2);
				
				apple_log($ausign_path.'ausign --sign '.$ipaName.' -c '.$p12.' -m '.$profile.' -p '.$p12pw.' -o '.$target_path.$UDID.'-'.$app_id.'.ipa');
				apple_log(json_encode($output));
				
				// 生成plist文件
				plist_write($target_path.$UDID.'-'.$app_id.'.plist',get_downloadurl($UDID.'-'.$app_id.'.ipa'),$acc_item['bundle'],$app_item['name'],$app_item['logo']);
				Db::name('spDevice')->where('id',$item['id'])->update(['isok'=>2]);
			}else{
				$acc_item = Db::name('spAcc')->where('id',$device_acc_item['acc_id'])->find();
				$bundleId_id = $acc_item['bundle_id'];
				$certificate_id = $acc_item['cert_id'];
				$p12 = $acc_item['p12'];
				$p12pw = $acc_item['p12pw'];
				// 已有描述文件直接签名
				$profile = $device_acc_item['device_mobileprovision'];
				// 签名生成安装包
				exec($ausign_path.'ausign --email 739218667@qq.com -p b123456', $output, $result1);
				
				apple_log($ausign_path.'ausign --email 739218667@qq.com -p b123456');

				exec($ausign_path.'ausign --sign '.$ipaName.' -c '.$p12.' -m '.$profile.' -p '.$p12pw.' -o '.$target_path.$UDID.'-'.$app_id.'.ipa', $output, $result2);

				apple_log($ausign_path.'ausign --sign '.$ipaName.' -c '.$p12.' -m '.$profile.' -p '.$p12pw.' -o '.$target_path.$UDID.'-'.$app_id.'.ipa');
				// 生成plist文件
				plist_write($target_path.$UDID.'-'.$app_id.'.plist',get_downloadurl($UDID.'-'.$app_id.'.ipa'),$acc_item['bundle'],$app_item['name'],$app_item['logo']);
				Db::name('spDevice')->where('id',$item['id'])->update(['isok'=>2]);

				apple_log($item['udid']."签名成功");
			}
		}else{
			apple_log($item['udid']."没有证书可签名");
		}
	}

	
}

$worker = new Worker();

$worker->onWorkerStart = function($worker){
	date_default_timezone_set('Asia/Shanghai');
	$time_interval = 5;
	app_signer();
	Timer::add($time_interval, 'app_signer');
};

// 运行worker
Worker::runAll();