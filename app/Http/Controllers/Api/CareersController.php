<?php

namespace App\Http\Controllers\Api;

use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;

/**
 * What the company website's Join Our Team page reads.
 *
 * Public on purpose — this is an advert, and a token on a careers page would
 * be a secret printed in the page source. It is read-only, rate limited, and
 * hands back only JobPosting::forWebsite(), so nothing internal can leak into
 * a page anybody can view.
 */
class CareersController
{
    public function index(): JsonResponse
    {
        $postings = JobPosting::live()
            ->with('department')
            ->orderByDesc('published_at')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $postings->map(fn (JobPosting $posting) => $posting->forWebsite())->all(),
            'count' => $postings->count(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $posting = JobPosting::live()->with('department')->where('slug', $slug)->first();

        if (! $posting) {
            // Same answer for a draft, a closed role and one that never
            // existed. A careers page is no place to confirm that the company
            // is quietly hiring for something.
            return response()->json([
                'error' => 'not_found',
                'message' => 'That role is no longer being advertised.',
            ], 404);
        }

        return response()->json(['data' => $posting->forWebsite()]);
    }
}
