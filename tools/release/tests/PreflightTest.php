<?php

// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Nextcloud\ReleaseTools\Tests;

use Nextcloud\ReleaseTools\MajorLifecycle;
use Nextcloud\ReleaseTools\Preflight;
use Nextcloud\ReleaseTools\PreflightResult;
use Nextcloud\ReleaseTools\ReleaseSchedule;
use Nextcloud\ReleaseTools\ScheduleGate;
use Nextcloud\ReleaseTools\Tests\Support\FakeGitHubApi;
use PHPUnit\Framework\TestCase;

/**
 * What: the checks that refuse a release before anything is tagged.
 *
 * Why: until now these lived in three places (release.sh, build/init,
 * schedule:check) and the first two only ran after the legacy script had
 * already tagged and uploaded. The 35.0.0 betas failed four times on a
 * version.php format ("35.0.0 beta 3") the build check could not read; the
 * comparison here is on the full version, so a forgotten RC bump is caught too.
 */
final class PreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/preflight-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        foreach (['stable34.json', 'stable35.json', 'master.json'] as $config) {
            file_put_contents("{$this->root}/{$config}", '[]');
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->root}/*") ?: []);
        rmdir($this->root);
    }

    private function api(string $ref, string $versionString): FakeGitHubApi
    {
        $api = new FakeGitHubApi();
        $api->seedFile(Preflight::SERVER, $ref, 'version.php', "<?php\n\$OC_VersionString = '{$versionString}';\n");
        // "Nextcloud 35" released 2026-09-17, 35.0.4 lands in December: mid-series.
        $api->seedMilestone('nextcloud/server', 1, 'Nextcloud 35', 'closed', 0, '2026-09-17T00:00:00Z');
        return $api;
    }

    private function preflight(FakeGitHubApi $api, string $input, bool $shadow = false, array $schedule = ['Nextcloud 35.0.5' => '2027-01-07']): PreflightResult
    {
        $path = tempnam(sys_get_temp_dir(), 'sched');
        file_put_contents($path, json_encode($schedule));
        try {
            return (new Preflight($api, new ScheduleGate($api, new MajorLifecycle($api)), $this->root))
                ->run($input, ReleaseSchedule::load($path), $shadow);
        } finally {
            unlink($path);
        }
    }

    public function testStableReleaseFromItsBranch(): void
    {
        $r = $this->preflight($this->api('stable35', '35.0.4'), '35.0.4');

        $this->assertSame([], $r->problems);
        $this->assertSame(['v35.0.4', 'stable35', 'stable35.json'], [$r->tag, $r->branch, $r->config]);
    }

    public function testBetaOfANewMajorComesFromMaster(): void
    {
        $r = $this->preflight($this->api('master', '36.0.0 beta 3'), '36.0.0beta3', schedule: []);

        $this->assertSame([], $r->problems);
        $this->assertSame(['master', 'master.json'], [$r->branch, $r->config]);
    }

    public function testLeadingVIsRefused(): void
    {
        $r = $this->preflight($this->api('stable35', '35.0.4'), 'v35.0.4');

        $this->assertSame(["Pass the version without 'v': '35.0.4', not 'v35.0.4'."], $r->problems);
    }

    public function testLeadingVIsOnlyANoteWhileTheLegacyScriptDispatches(): void
    {
        $r = $this->preflight($this->api('stable35', '35.0.4'), 'v35.0.4', shadow: true);

        $this->assertTrue($r->passed());
        $this->assertCount(1, $r->notes);
    }

    public function testUppercaseSuffixIsNotAVersion(): void
    {
        // The "v35.0.0RC2" run of 2026-09: a typo, re-dispatched as rc2.
        $r = $this->preflight($this->api('stable35', '35.0.0 RC2'), '35.0.0RC2');

        $this->assertStringContainsString('is not a release version', $r->problems[0]);
    }

    public function testExistingTagIsRefused(): void
    {
        $api = $this->api('v35.0.4', '35.0.4');
        $api->seedTag(Preflight::SERVER, 'v35.0.4');

        $this->assertSame(['v35.0.4 already exists in nextcloud/server.'], $this->preflight($api, '35.0.4')->problems);
    }

    public function testExistingTagIsExpectedWhileTheLegacyScriptTagsAndIsTheRefChecked(): void
    {
        // The tag's version.php is what ships; the branch may have moved on.
        $api = $this->api('v35.0.4', '35.0.4');
        $api->seedFile(Preflight::SERVER, 'stable35', 'version.php', "<?php\n\$OC_VersionString = '35.0.5 RC1';\n");
        $api->seedTag(Preflight::SERVER, 'v35.0.4');
        $api->seedTag(Preflight::RELEASES, 'v35.0.4');

        $this->assertSame([], $this->preflight($api, '35.0.4', shadow: true)->problems);
    }

    public function testForgottenRcBumpIsCaught(): void
    {
        $problems = $this->preflight($this->api('stable35', '35.0.4 RC1'), '35.0.4rc2')->problems;

        $this->assertSame(["version.php at stable35 says '35.0.4 RC1', not 35.0.4rc2. Is the version bump merged?"], $problems);
    }

    public function testMissingConfigIsReported(): void
    {
        $problems = $this->preflight($this->api('stable33', '33.0.12'), '33.0.12', schedule: ['Nextcloud 33.0.13' => '2027-01-07'])->problems;

        $this->assertSame(['stable33.json is missing: add it with the apps bundled in 33.'], $problems);
    }

    public function testScheduleProblemsAreIncluded(): void
    {
        $problems = $this->preflight($this->api('stable35', '35.0.4'), '35.0.4', schedule: [])->problems;

        $this->assertCount(1, $problems);
        $this->assertStringContainsString("No due date for 'Nextcloud 35.0.5'", $problems[0]);
    }

    public function testVersionStringsNormalize(): void
    {
        $this->assertSame('35.0.0beta3', Preflight::normalize('35.0.0 beta 3'));
        $this->assertSame('35.0.3rc2', Preflight::normalize('35.0.3 RC2'));
        $this->assertSame('35.0.3', Preflight::normalize('35.0.3'));
    }
}
