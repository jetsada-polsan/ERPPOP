<?php

namespace App\Http\Controllers;

use App\Services\MasterData\SafeDelete;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MasterDataDeleteController extends Controller
{
    public function destroy(Request $request, string $type, int $id, SafeDelete $safeDelete): RedirectResponse
    {
        abort_unless(isset(SafeDelete::TYPES[$type]), 404);
        abort_unless($request->user()?->hasPermission(SafeDelete::TYPES[$type][2]), 403);
        try {
            $safeDelete->delete($type, $id, $request->user()->id);
        } catch (RuntimeException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'ลบแล้ว');
    }
}
