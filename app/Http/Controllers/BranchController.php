<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('branches.index', [
            'branches' => Branch::withCount('customers')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Branch::create($this->validated($request));

        return back()->with('success', 'Branch added successfully.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $branch->update($this->validated($request, $branch));

        return back()->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->customers()->exists()) {
            return back()->with('error', 'This branch is assigned to customers. Move those customers before deleting it.');
        }
        if ($branch->users()->exists()) {
            return back()->with('error', 'This branch is assigned to panel users. Move or delete those users first.');
        }

        $branch->delete();

        return back()->with('success', 'Branch deleted.');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('branches', 'name')->ignore($branch)],
            'operator_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
