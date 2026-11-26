<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerationInput;
use App\Models\Generation;
use App\Pixelforge\Quotes;
use App\Pixelforge\Recovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = DB::table('generations as g')
            ->join('accepted_quotes as q', 'q.generation_id', '=', 'g.id')
            ->leftJoin('final_bills as b', 'b.generation_id', '=', 'g.id')
            ->where('g.user_id', $request->user()->id)->orderByDesc('g.created_at')
            ->select('g.id', 'g.status', 'g.message', 'g.image_url', 'g.image_expires_at', 'g.created_at', 'q.minimum', 'q.maximum', 'b.customer_charge')
            ->selectRaw("g.payload->>'prompt' as prompt")->simplePaginate(20);
        $active = Generation::where('user_id', $request->user()->id)->whereNotNull('recover_at')->exists();

        return Inertia::render('Workspace', ['history' => $rows, 'active' => $active, 'userId' => $request->user()->id, 'isOperator' => (bool) $request->user()->is_operator]);
    }

    public function quote(GenerationInput $request, Quotes $quotes): JsonResponse
    {
        return response()->json($quotes->offer($request->payload(), $request->user()->id));
    }

    public function store(GenerationInput $request, Quotes $quotes, Recovery $recovery): JsonResponse
    {
        $userId = $request->user()->id;
        $input = $request->payload();
        $key = $request->validated('submission_key');
        $find = fn () => Generation::where('user_id', $userId)->where('submission_key', $key)->first();
        $generation = $find();
        if (! $generation) {
            $quote = $quotes->accept($request->validated('quote_token'), $input, $userId);
            $generation = DB::transaction(function () use ($userId, $key, $input, $quote, $find) {
                DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                if ($existing = $find()) {
                    return $existing;
                }
                $id = (string) Str::uuid();
                $row = Generation::create(['id' => $id, 'user_id' => $userId, 'submission_key' => $key, 'payload' => [...$input, 'metadata' => ['local_id' => $id]], 'recover_at' => now()]);
                DB::table('accepted_quotes')->insert(['generation_id' => $id, 'pricing' => json_encode($quote['pricing'], JSON_THROW_ON_ERROR), 'minimum' => $quote['min'], 'maximum' => $quote['max'], 'quoted_at' => $quote['quoted_at'], 'accepted_at' => now()]);

                return $row;
            });
        }
        $saved = $generation->payload;
        unset($saved['metadata']);
        abort_unless($saved == $input, 409, 'Submission identifier already used for different inputs.');
        $recovery->run($generation->id);

        return response()->json(['id' => $generation->id, 'status' => $generation->fresh()->status], 202);
    }
}
