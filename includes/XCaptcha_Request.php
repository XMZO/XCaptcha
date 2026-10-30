<?php

/**
 * XCaptcha请求相关
 * @author CairBin(Xinyi Liu)
 */
class XCaptcha_Request
{
    /**
     * curl请求并返回响应，用于服务端向验证服务器的校验
	 * @static 
     * @param  string $urlPath   请求路径
     * @param  string $secretKey 密钥
     * @param  string $postToken token
     * @return object 响应结果
     */    
    public static function makeCaptchaRequest($urlPath, $secretKey, $postToken)
    {
        $postData = http_build_query([
			'secret' => $secretKey,
			'response' => $postToken
		]);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $urlPath);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 secs timeout
		
		$response = curl_exec($ch);
		
		if (curl_errno($ch)) {
			echo '请求错误: ' . curl_error($ch);
		}
		
		curl_close($ch);
        return json_decode($response);
    }

    /**
     * 以 JSON 请求体 POST 并返回解码后的数组（Cap 的 siteverify 只收 JSON）
     * @static
     * @param  string $urlPath 请求地址
     * @param  array  $data    请求体
     * @return array|null      响应 JSON，请求失败或不是 JSON 时为 null
     */
    public static function makeJsonRequest($urlPath, array $data)
    {
        $ch = curl_init($urlPath);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            // PHP 的 curl 默认不带 User-Agent，CloudFront 等 CDN 的防护会直接拦掉这种请求
            CURLOPT_USERAGENT => 'XCaptcha (Typecho)',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            // 不能像上面那样 echo：登录校验失败后还要跳转，提前输出会让跳转失效
            error_log('XCaptcha: 请求 ' . $urlPath . ' 失败: ' . curl_error($ch));
        }
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        return is_array($decoded) ? $decoded : null;
    }
}