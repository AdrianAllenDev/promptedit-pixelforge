<?php

namespace App\Http\Controllers;

use App\Pixelforge\GenerationReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationReviewController extends Controller
{
    public function index(GenerationReview $review): Response
    {
        return Inertia::render('GenerationReview', ['cases' => $review->cases()]);
    }

    public function release(Request $request, string $generation, GenerationReview $review): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $review->release($generation, $request->user('admin'), $data['reason']);

        return to_route('admin.generations.review', $request->only('page'));
    }
}
