<?php
declare(strict_types=1);

use Xne\Client\Detect;

return [
    'an extension only composer.lock names (no install since the lock refresh) is a 7x package' => static function (): void {
        $root = t_fake_install(t_scratch('detect-lock'), '6.0.15', [
            'extension/tron/extension.xml' => "<extension><metadata><version>0.0.0.13</version></metadata></extension>\n",
            'extension/eztags/extension.xml' => "<extension><metadata><version>2.4.32</version></metadata></extension>\n",
            'extension/shield/ezinfo.php' => "<?php class shieldInfo { static function info() { return ['Version' => '0.0.1.8']; } }\n",
            'extension/local_thing/ezinfo.php' => "<?php\n",
            'vendor/composer/installed.json' => json_encode(['packages' => [
                ['name' => 'se7enxweb/eztags', 'version' => 'v2.4.9', 'install-path' => '../../extension/eztags'],
            ]]),
            'composer.lock' => json_encode(['packages' => [
                ['name' => 'se7enxweb/eztags', 'version' => 'v2.4.32', 'type' => 'ezpublish-legacy-extension'],
                ['name' => 'se7enxweb/tron', 'version' => '0.0.0.13', 'type' => 'ezpublish-legacy-extension',
                 'extra' => ['installer-name' => 'tron', 'ezpublish-legacy-extension-name' => 'tron']],
                ['name' => 'se7enxweb/exp_request_shield', 'version' => 'v0.0.1.8', 'type' => 'ezpublish-legacy-extension',
                 'extra' => ['ezpublish-legacy-extension-name' => 'shield']],
                ['name' => 'se7enxweb/expcomponents-base', 'version' => '1.9.6', 'type' => 'library'],
            ], 'packages-dev' => []]),
        ]);
        $by = [];
        foreach (Detect::extensions($root, []) as $e) {
            $by[$e['name']] = $e;
        }
        t_same('7x', $by['tron']['kind'], 'tron: only in composer.lock');
        t_same('se7enxweb/tron', $by['tron']['composer']);
        t_same('0.0.0.13', $by['tron']['version'], 'the version still comes from the extension itself');
        t_same('7x', $by['shield']['kind'], 'the folder is extra.ezpublish-legacy-extension-name');
        t_same('se7enxweb/exp_request_shield', $by['shield']['composer']);
        t_same('7x', $by['eztags']['kind'], 'installed.json still counts');
        t_same('third-party', $by['local_thing']['kind'], 'a folder no package names stays third-party');
    },
    'installed.json wins over composer.lock, and a lock without legacy extensions changes nothing' => static function (): void {
        $root = t_fake_install(t_scratch('detect-lock2'), '6.0.15', [
            'extension/eztags/ezinfo.php' => "<?php\n",
            'extension/other/ezinfo.php' => "<?php\n",
            'vendor/composer/installed.json' => json_encode([
                ['name' => 'se7enxweb/eztags', 'version' => 'v2.4.9', 'install-path' => '../../extension/eztags'],
            ]),
            'composer.lock' => json_encode(['packages' => [
                ['name' => 'someone/eztags', 'version' => '9.9.9', 'type' => 'ezpublish-legacy-extension'],
                ['name' => 'se7enxweb/other', 'version' => '1.0.0', 'type' => 'library'],
            ]]),
        ]);
        $by = [];
        foreach (Detect::extensions($root, []) as $e) {
            $by[$e['name']] = $e;
        }
        t_same('se7enxweb/eztags', $by['eztags']['composer'], 'the installed package, not the lock entry');
        t_same('7x', $by['eztags']['kind']);
        t_same('third-party', $by['other']['kind'], 'a library in the lock is no extension');
        t_same(null, $by['other']['composer']);
    },
];
