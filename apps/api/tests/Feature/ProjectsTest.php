<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\SemanticModel;
use App\Models\User;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Projects keep sources, models, dashboards and reports apart inside one
 * organisation. Uploads write real tables, so this commits and cleans up.
 */
class ProjectsTest extends TestCase
{
    private const ENGINEER = 'engineer@emgs.demo';

    protected function setUp(): void
    {
        parent::setUp();
        if (! TenantScopeBypass::run(fn () => Organisation::where('slug', 'emgs')->exists())) {
            Artisan::call('migrate:fresh', ['--seed' => true]);
        }
    }

    protected function tearDown(): void
    {
        TenantScopeBypass::run(function () {
            $sources = DataSource::withoutGlobalScope('project')->where('name', 'Budget Lines (upload)')->get();
            foreach (Dataset::withoutGlobalScope('project')->whereIn('data_source_id', $sources->pluck('id'))->get() as $d) {
                SemanticModel::withoutGlobalScope('project')->where('base_dataset_id', $d->id)->delete();
                DB::statement('DROP TABLE IF EXISTS analytics."'.$d->physical_table.'"');
                $d->delete();
            }
            $sources->each(fn ($s) => $s->delete());
            Project::where('key', 'like', 'finance_planning%')->delete();
        });
        parent::tearDown();
    }

    private function csv(): UploadedFile
    {
        $csv = "period,cost_centre,amount\n";
        foreach (range(1, 30) as $i) {
            $csv .= now()->subDays($i)->toDateString().',CC-'.($i % 4).','.(100 * $i)."\n";
        }

        return UploadedFile::fake()->createWithContent('Budget Lines.csv', $csv);
    }

    /** Signs in as the person, working in the project given (none: every project they may open). Headers never carry over. */
    private function in(string $email, ?string $project = null): static
    {
        $this->flushHeaders();
        $this->as($email);

        return $project ? $this->withHeader('X-Project-Id', $project) : $this;
    }

    /** @return array<string, mixed> */
    private function upload(string $project): array
    {
        return $this->in(self::ENGINEER, $project)
            ->post('/api/v1/data/upload', ['file' => $this->csv()], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_existing_work_lives_in_the_default_project(): void
    {
        $projects = $this->in('viewer@emgs.demo')->getJson('/api/v1/projects')->assertOk()->json('data');
        $general = collect($projects)->firstWhere('is_default', true);
        $this->assertSame('general', $general['key']);
        $this->assertSame('organisation', $general['visibility']);
        $this->assertGreaterThan(0, $general['counts']['dashboards']);
        $this->assertSame(0, TenantScopeBypass::run(fn () => Dashboard::withoutGlobalScope('project')->whereNull('project_id')->count()));
    }

    public function test_projects_keep_work_apart(): void
    {
        $general = $this->in(self::ENGINEER)->getJson('/api/v1/projects')->json('data.0.id');

        // Viewers cannot start projects; people who connect data can, and become the owner.
        $this->in('viewer@emgs.demo')->postJson('/api/v1/projects', ['name' => 'Nope'])->assertForbidden();
        $finance = $this->in(self::ENGINEER)->postJson('/api/v1/projects', ['name' => 'Finance planning', 'description' => 'Budgets'])
            ->assertCreated()->json('data');
        $this->assertSame('finance_planning', $finance['key']);
        $this->assertSame('owner', $finance['my_role']);
        $this->assertSame('members', $finance['visibility']);

        // A members-only project is invisible to others, and naming it is refused.
        $this->assertNotContains($finance['id'], collect($this->in('viewer@emgs.demo')->getJson('/api/v1/projects')->json('data'))->pluck('id'));
        $this->in('viewer@emgs.demo', $finance['id'])->getJson('/api/v1/dashboards')
            ->assertForbidden()->assertJsonPath('error.code', 'project_forbidden');
        // Administrators open every project.
        $this->assertContains($finance['id'], collect($this->in('admin@emgs.demo')->getJson('/api/v1/projects')->json('data'))->pluck('id'));

        // The same file in two projects lands in two tables, each in its own project.
        $inFinance = $this->upload($finance['id']);
        $inGeneral = $this->upload($general);
        $this->assertSame($finance['id'], $inFinance['source']['project_id']);
        $this->assertSame($general, $inGeneral['source']['project_id']);
        $this->assertNotSame($inFinance['dataset']['physical_table'], $inGeneral['dataset']['physical_table']);
        $this->assertSame(30, (int) DB::connection('analytics')->table('analytics.'.$inFinance['dataset']['physical_table'])->count());

        // Each project lists only its own work; with no project chosen, everything the person may open.
        $names = fn (?string $p) => collect(($p ? $this->in(self::ENGINEER, $p) : $this->in(self::ENGINEER))
            ->getJson('/api/v1/data-sources')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($inFinance['source']['id'], $names($finance['id']));
        $this->assertNotContains($inGeneral['source']['id'], $names($finance['id']));
        $this->assertNotContains($inFinance['source']['id'], $names($general));
        $this->assertContains($inFinance['source']['id'], $names(null));
        $this->assertSame([], $this->in(self::ENGINEER, $finance['id'])->getJson('/api/v1/dashboards')->json('data'));
        $this->in(self::ENGINEER, $general)->getJson("/api/v1/datasets/{$inFinance['dataset']['id']}")->assertNotFound();

        // A data model in one project cannot be built on another project's data.
        $this->in(self::ENGINEER, $general)->postJson("/api/v1/datasets/{$inFinance['dataset']['id']}/semantic-model", [])->assertNotFound();
        $this->in(self::ENGINEER, $finance['id'])
            ->postJson("/api/v1/datasets/{$inFinance['dataset']['id']}/semantic-model", [])->assertCreated();

        // Members: adding someone opens the project to them; the last owner stays.
        $viewer = TenantScopeBypass::run(fn () => User::where('email', 'viewer@emgs.demo')->value('id'));
        $owner = TenantScopeBypass::run(fn () => User::where('email', self::ENGINEER)->value('id'));
        $this->in('viewer@emgs.demo')->postJson("/api/v1/projects/{$finance['id']}/members", ['user_id' => $viewer])->assertNotFound();
        $this->assertContains($viewer, collect($this->in(self::ENGINEER)->getJson("/api/v1/projects/{$finance['id']}/people")->assertOk()->json('data'))->pluck('id'));
        $members = $this->in(self::ENGINEER)->postJson("/api/v1/projects/{$finance['id']}/members", ['user_id' => $viewer])->assertOk()->json('data.members');
        $this->assertCount(2, $members);
        $this->assertContains($finance['id'], collect($this->in('viewer@emgs.demo')->getJson('/api/v1/projects')->json('data'))->pluck('id'));
        $this->in('viewer@emgs.demo')->patchJson("/api/v1/projects/{$finance['id']}", ['name' => 'Mine now'])->assertForbidden();
        $this->in(self::ENGINEER)->deleteJson("/api/v1/projects/{$finance['id']}/members/{$owner}")->assertStatus(422);

        // A project with work in it cannot be deleted; the default project never can.
        $this->in(self::ENGINEER)->deleteJson("/api/v1/projects/{$finance['id']}")->assertStatus(409)->assertJsonPath('error.code', 'project_not_empty');
        $this->in('admin@emgs.demo')->deleteJson("/api/v1/projects/{$general}")->assertStatus(422);
        $this->in('admin@emgs.demo')->patchJson("/api/v1/projects/{$general}", ['visibility' => 'members'])->assertStatus(422);
    }

    public function test_an_empty_project_can_be_deleted(): void
    {
        $p = $this->in(self::ENGINEER)->postJson('/api/v1/projects', ['name' => 'Finance planning'])->assertCreated()->json('data.id');
        $this->in(self::ENGINEER)->deleteJson("/api/v1/projects/{$p}")->assertOk();
        $this->assertFalse(TenantScopeBypass::run(fn () => Project::whereKey($p)->exists()));
    }
}
