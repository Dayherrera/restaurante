<?php

namespace App\Http\Controllers;

use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrintAgentController extends Controller
{
    private function authenticate(Request $r): void
    {
        $token = config('pos.print_token');
        abort_unless($token && hash_equals($token, (string) $r->bearerToken()), 401);
    }

    public function claim(Request $r)
    {
        $this->authenticate($r);
        $data = $r->validate(['areas' => 'required|array|min:1', 'areas.*' => 'integer|exists:print_areas,id']);

        return DB::transaction(function () use ($data) {
            // Expired jobs are held for manual reconciliation to avoid automatic duplicate tickets.
            PrintJob::where('status', 'processing')->where('leased_at', '<', now()->subMinutes(2))->update(['status' => 'failed', 'error' => 'Confirmación del agente perdida; verifica la impresora antes de reintentar.']);
            $job = PrintJob::where('status', 'pending')->whereIn('print_area_id', $data['areas'])->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return response()->json(['job' => null]);
            }
            $job->update(['status' => 'processing', 'lease_token' => Str::uuid(), 'leased_at' => now(), 'attempts' => $job->attempts + 1]);
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $job->payload);
            $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $text);

            return response()->json(['job' => ['id' => $job->id, 'area_id' => $job->print_area_id, 'lease_token' => $job->lease_token, 'payload_base64' => base64_encode("\x1B\x40".$text."\n\n\n\x1D\x56\x00")]]);
        });
    }

    public function ack(Request $r, PrintJob $job)
    {
        $this->authenticate($r);
        $d = $r->validate(['lease_token' => 'required|uuid', 'success' => 'required|boolean', 'error' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($job, $d) {
            $job = PrintJob::lockForUpdate()->findOrFail($job->id);
            abort_unless(hash_equals((string) $job->lease_token, $d['lease_token']), 409);
            if (in_array($job->status, ['printed', 'failed'])) {
                return;
            } $job->update(['status' => $d['success'] ? 'printed' : 'failed', 'printed_at' => $d['success'] ? now() : null, 'error' => $d['success'] ? null : ($d['error'] ?? 'Error de impresión')]);
        });

        return response()->json(['ok' => true]);
    }
}
