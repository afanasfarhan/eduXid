<?php

if (!function_exists('get_local_ip')) {
    function get_local_ip() {
        // Coba gunakan metode Socket (Paling akurat untuk mendapatkan IP Wi-Fi)
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock !== false) {
                try {
                    @socket_connect($sock, "8.8.8.8", 53);
                    @socket_getsockname($sock, $addr);
                    if (isset($addr) && filter_var($addr, FILTER_VALIDATE_IP)) {
                        return $addr;
                    }
                } catch (\Exception $e) {
                    // Abaikan error
                } finally {
                    @socket_close($sock);
                }
            }
        }

        // Fallback jika socket tidak aktif
        return getHostByName(getHostName());
    }
}