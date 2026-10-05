<?php
declare(strict_types=1);

class MeekroDB
{
    public array $rows = [];
    public bool $ready = true;

    public function queryFirstField(string $query, mixed ...$parameters): int
    {
        return $this->ready ? 1 : 0;
    }

    public function queryFirstRow(string $query, mixed ...$parameters): ?array
    {
        return isset($this->rows[$parameters[0]])
            ? ['copy_json' => $this->rows[$parameters[0]]] : null;
    }

    public function query(string $query, mixed ...$parameters): void
    {
        $this->rows[$parameters[0]] = $parameters[1];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Content\SiteCopyRepository;

$db = new MeekroDB();
$copy = new SiteCopyRepository($db);
$original = $copy->load('cs');
if ($original !== SiteCopyRepository::DEFAULTS) throw new RuntimeException('Missing copy defaults failed.');
$changed = array_replace($original, [
    'hero_title' => 'Výprava do hor', 'footer_intro' => 'Vybavení & odolnost',
]);
$copy->save('cs', $changed);
if ($copy->load('cs') !== $changed || $copy->load('en') !== $original) {
    throw new RuntimeException('Site copy lost its language or field values.');
}
foreach ([['hero_title' => '<script>' . str_repeat('a', 100)],
    ['footer_intro' => "Nevhodný\nřádek"], ['hero_subtitle' => '']] as $bad) {
    try {
        $copy->save('cs', array_replace($changed, $bad));
        throw new RuntimeException('Invalid site copy was accepted.');
    } catch (InvalidArgumentException $expected) {}
}
if ($copy->load('cs') !== $changed) throw new RuntimeException('Invalid copy changed saved data.');
$db->ready = false;
if ($copy->load('cs') !== $original) throw new RuntimeException('Legacy schema must keep default copy.');
echo "Site copy settings OK\n";
