<?php
class Cipher {
    private $method;
    private $key = null;
    private $iv = null; 

    public function __construct($method = 'aes-256-ecb') {
        $this->method = $method;
    }

    public function encrypt($data, $key = null, $iv = null) {
        $this->setKey($key);
        $out = openssl_encrypt($data, $this->method, $this->key, OPENSSL_RAW_DATA);
        return base64_encode($out);
    }

    public function decrypt($data, $key = null, $iv = null) {
        $this->setKey($key);
        $data = base64_decode($data);
        $out = openssl_decrypt($data, $this->method, $this->key, OPENSSL_RAW_DATA);
        return trim($out);
    }

    private function setKey($key) {
        if (!is_null($key)) {
            $this->key = hash("sha256", $key, true);
        }
    }
}
?>
