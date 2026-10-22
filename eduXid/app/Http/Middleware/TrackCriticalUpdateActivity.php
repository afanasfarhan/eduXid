<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class TrackCriticalUpdateActivity {
    public function handle(Request $request, Closure $next): Response {
        $path=strtolower(trim($request->path(),'/'));
        $critical= $request->method()!=='GET' && (
            str_contains($path,'cbt') || str_contains($path,'ljk') ||
            str_contains($path,'sync') || str_contains($path,'impor') ||
            str_contains($path,'upload') || str_contains($path,'koreksi')
        );
        if(!$critical)return $next($request);
        $folder=rtrim((string)config('eduxid.install_root'), '\\/').DIRECTORY_SEPARATOR.'.eduxid-updater'.DIRECTORY_SEPARATOR.'critical';
        if(!is_dir($folder))@mkdir($folder,0770,true);
        $file=$folder.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12)).'.busy';
        if(@file_put_contents($file,(string)time(),LOCK_EX)===false){
            // If readiness tracking cannot be installed, fail closed on the update side via marker.
            @file_put_contents(dirname($folder).DIRECTORY_SEPARATOR.'critical-tracking-error.flag',(string)time());
        }
        try{return $next($request);}finally{@unlink($file);}
    }
}
