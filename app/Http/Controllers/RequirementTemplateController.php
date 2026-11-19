<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Blank forms (templates) for the internship requirements.
 * Everyone who is signed in can list them (interns download and print them);
 * the OJT coordinator and the admin upload and remove them.
 */
class RequirementTemplateController extends Controller
{
    private function disk(): string
    {
        return config('filesystems.uploads', 'public');
    }

    // GET /requirement-templates
    public function index()
    {
        return response()->json([
            'templates' => DB::table('requirement_templates')->orderBy('requirement')->orderBy('title')->get(),
            // { requirement key: note }, "__general" is the note at the top of the list
            // the notes table is new: the forms still load if the migration has not been run yet
            'notes'     => \App\Services\SchemaCache::has('requirement_form_notes') ? DB::table('requirement_form_notes')->pluck('note', 'requirement') : (object) [],
        ]);
    }

    // PUT /coordinator/requirement-form-notes  (json: requirement, note)  - an empty note removes it
    public function saveNote(Request $request)
    {
        $data = $request->validate([
            'requirement' => 'required|string|max:60',
            'note'        => 'nullable|string|max:600',
        ]);
        if (!\App\Services\SchemaCache::has('requirement_form_notes')) {
            return response()->json(['message' => 'Notes are not set up yet. Run php artisan migrate on the server.'], 500);
        }
        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            DB::table('requirement_form_notes')->where('requirement', $data['requirement'])->delete();
            return response()->json(['message' => 'Note removed.']);
        }
        $actor = $request->attributes->get('auth_user');
        DB::table('requirement_form_notes')->updateOrInsert(
            ['requirement' => $data['requirement']],
            ['note' => $note, 'updated_by' => $actor->id ?? null, 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->json(['message' => 'Note saved.']);
    }

    // POST /coordinator/requirement-templates  (multipart: file, title, requirement?)
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:150',
            'requirement' => 'nullable|string|max:60',
            // checked by the file's name (.docx and so on): Word files saved from Google Docs or other apps
            // are often read as a plain zip, and "mimes" then turned them away
            'file'        => 'required|file|extensions:pdf,doc,docx,xls,xlsx,ppt,pptx|max:15360',
        ], [
            'file.extensions' => 'Only PDF, Word, Excel or PowerPoint files can be added.',
            'file.max'        => 'The file is too big. The limit is 15 MB.',
        ]);

        $file = $request->file('file');
        // keep the real extension, so the saved copy opens as .docx and not as .zip
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('templates', Str::random(40) . '.' . $ext, $this->disk());
        $actor = $request->attributes->get('auth_user');

        $id = DB::table('requirement_templates')->insertGetId([
            'requirement'   => ($data['requirement'] ?? null) ?: null,
            'title'         => $data['title'],
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'size'          => $file->getSize(),
            'uploaded_by'   => $actor->id ?? null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['message' => 'Form uploaded.', 'id' => $id], 201);
    }

    // DELETE /coordinator/requirement-templates/{id}
    public function destroy($id)
    {
        $row = DB::table('requirement_templates')->where('id', $id)->first();
        if (!$row) return response()->json(['message' => 'Form not found.'], 404);

        Storage::disk($this->disk())->delete($row->file_path);
        DB::table('requirement_templates')->where('id', $id)->delete();

        return response()->json(['message' => 'Form removed.']);
    }
}