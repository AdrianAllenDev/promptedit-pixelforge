<?php

namespace App\Http\Controllers;

use App\Pixelforge\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function show(Request $request, Wallet $wallet): Response
    {
        abort_unless(config('services.pixelforge.wallets_enabled'), 404);

        return Inertia::render('Wallet', ['balance' => $wallet->balance($request->user()->id), 'amounts' => Wallet::PURCHASE_AMOUNTS, 'userId' => $request->user()->id]);
    }

    public function purchase(Request $request, Wallet $wallet): JsonResponse
    {
        abort_unless(config('services.pixelforge.wallets_enabled'), 404);
        $data = $request->validate(['purchase_key' => ['required', 'uuid'], 'amount' => ['required', 'string', Rule::in(Wallet::PURCHASE_AMOUNTS)]]);
        $id = $request->user()->id;
        $wallet->topUp($id, $data['purchase_key'], $data['amount']);

        return response()->json($wallet->balance($id));
    }
}
