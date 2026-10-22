<?php
namespace App\Http\Controllers;

use App\Services\Sync\EduxidCloudConfig;
use App\Services\Broadcast\BroadcastInboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class BroadcastMessageController extends Controller
{
    public function index(Request $request)
    {
        $role = strtolower((string) $request->user()->role);
        abort_unless(in_array($role, ['admin','guru','wali_kelas','guru_bk','kepala_sekolah','pengajar'], true), 403);
        $assignments = collect();
        if ($role !== 'admin') {
            $teacher = $request->user()->teacher();
            if ($teacher && Schema::hasTable('teaching_assignments') && Schema::hasTable('homerooms')) {
                // Kolom resmi rombel eduXid adalah `nama`, bukan `name`.
                // Pada instalasi lama, beri fallback agar halaman tidak gagal dibuka.
                $labelColumn = Schema::hasColumn('homerooms', 'nama')
                    ? 'nama'
                    : (Schema::hasColumn('homerooms', 'name') ? 'name' : null);

                if ($labelColumn !== null) {
                    $qualifiedLabel = 'homerooms.' . $labelColumn;
                    $assignments = DB::table('teaching_assignments')
                        ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
                        ->where('teaching_assignments.teacher_id', $teacher->id)
                        ->select('homerooms.id', DB::raw($qualifiedLabel . ' AS name'))
                        ->distinct()
                        ->orderBy($qualifiedLabel)
                        ->get();
                }
            }
        }
        $inbox = app(BroadcastInboxService::class);
        $inboxStatus = $inbox->refresh($request);
        $messages = $inbox->cached($request->user(), 100, false);
        $inbox->markRead($request);
        return view('broadcast.index', compact('role', 'assignments', 'messages', 'inboxStatus'));
    }

    /** Endpoint polling banner; tidak mengubah status sudah dibaca. */
    public function preview(Request $request, BroadcastInboxService $inbox)
    {
        abort_unless($inbox->allowed($request->user()), 403);
        $inboxStatus = $inbox->refresh($request);
        // Banner selalu menampilkan pesan TERBARU, bukan hanya yang belum dibaca.
        $messages = $inbox->cached($request->user(), 3, false);
        $unreadCount = $inbox->unreadCount($request->user());
        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'inbox_status' => $inboxStatus,
            'html' => view('broadcast.partials.dashboard-banner', compact('messages', 'unreadCount'))->render(),
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Hapus di inbox lokal perangkat ini saja; tetap tersembunyi saat Cloud dipoll. */
    public function destroy(Request $request, string $messageId, BroadcastInboxService $inbox)
    {
        abort_unless($inbox->allowed($request->user()), 403);
        try {
            if (!$inbox->deleteMessage($request, $messageId)) {
                return back()->with('error', 'Pesan tidak ditemukan atau sudah dihapus.')
                    ->withErrors(['broadcast' => 'Pesan tidak ditemukan atau sudah dihapus.']);
            }
            return back()
                ->with('success', 'Pesan berhasil dihapus dari perangkat ini.')
                ->with('status', 'Pesan berhasil dihapus dari perangkat ini.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())
                ->withErrors(['broadcast' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::warning('Gagal menghapus pesan lokal', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menghapus pesan. Coba kembali.')
                ->withErrors(['broadcast' => 'Gagal menghapus pesan. Coba kembali.']);
        }
    }

    public function send(Request $request)
    {
        $role = strtolower((string)$request->user()->role);
        abort_unless(in_array($role,['admin','guru','wali_kelas','guru_bk','kepala_sekolah','pengajar'],true),403);
        $rules = ['title'=>'required|string|min:3|max:160','body'=>'required|string|min:3|max:4000','priority'=>'required|in:normal,important'];
        if ($role !== 'admin') $rules['target_homeroom_id']='required|integer|min:1';
        $data=$request->validate($rules);
        if($role !== 'admin') {
            $teacher=$request->user()->teacher();
            abort_unless($teacher && DB::table('teaching_assignments')->where('teacher_id',$teacher->id)->where('homeroom_id',(int)$data['target_homeroom_id'])->exists(),403);
        }
        try {
            $inbox = app(BroadcastInboxService::class);
            $auth = $inbox->credentials($request);
            $body = ['target_type'=>$role==='admin'?'gtk':'class', 'target_homeroom_id'=>$role==='admin'?null:(string)$data['target_homeroom_id'], 'title'=>$data['title'], 'body'=>$data['body'], 'priority'=>$data['priority']];
            $response=$inbox->cloudClient($auth['scheme'],$auth['token'])->timeout(20)->post('/api/broadcast/send',$body);
            if(!$response->successful()||!$response->json('success'))throw new RuntimeException((string)($response->json('error.message') ?: 'HTTP '.$response->status()));
            return back()->with('status','Pengumuman terkirim melalui Cloud.');
        }catch(Throwable $e){Log::warning('Broadcast gagal',['error'=>$e->getMessage()]);return back()->withErrors(['broadcast'=>'Pengiriman gagal: '.$e->getMessage()])->withInput();}
    }

}
