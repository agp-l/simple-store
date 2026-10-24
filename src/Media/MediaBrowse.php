<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use InvalidArgumentException;

/** Read-only folder presentation, independent of physical files and custom collection membership. */
final class MediaBrowse
{
    public const SORTS = ['newest' => 'Nejnovější', 'name' => 'Název A–Z', 'oldest' => 'Nejstarší', 'size' => 'Největší soubory'];

    public static function options(array $input): array
    {
        $folder = $input['folder'] ?? 'all'; $owner = $input['owner'] ?? '';
        $search = $input['q'] ?? ''; $sort = $input['sort'] ?? 'newest';
        $view = $input['view'] ?? (is_string($folder) && isset(MediaOwners::TYPES[$folder]) ? 'folders' : 'photos');
        $offset = filter_var($input['offset'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if (!is_string($folder) || !is_string($owner) || !is_string($search) || strlen($search) > 200 ||
            !is_string($sort) || !isset(self::SORTS[$sort]) || !in_array($view, ['folders', 'photos'], true) || $offset === false ||
            ($owner !== '' && (!isset(MediaOwners::TYPES[$folder]) || preg_match('/^[a-f0-9]{32}$/D', $owner) !== 1))) {
            throw new InvalidArgumentException('Neplatný filtr knihovny médií.');
        }
        if (!isset(MediaOwners::TYPES[$folder]) || $owner !== '') $view = 'photos';
        return compact('folder', 'owner', 'view', 'sort', 'offset') + ['q' => trim($search)];
    }

    public static function listing(array $files, array $state, array $owners, array $options, int $limit = 48): array
    {
        $folder = $options['folder']; $scope = $options['owner'] === '' ? '' : $folder . ':' . $options['owner'];
        if (!isset(MediaCatalog::GROUPS[$folder])) MediaCatalog::requireFolder($state, $folder);
        $labels = MediaCatalog::folderLabels($state); $directories = []; $matchingFiles = []; $counts = array_fill_keys(array_keys(MediaCatalog::GROUPS), 0);
        $allBytes = 0;
        foreach ($files as $file) {
            $counts['all']++; $allBytes += $file['bytes'];
            if ($file['folder'] === '') $counts['unfiled']++;
            if (isset(MediaOwners::TYPES[$file['group']])) $counts[$file['group']]++;
            $fileScope = MediaOwners::scope($file['path']);
            $owner = $owners[$fileScope ?? ''] ?? null;
            $file['owner_scope'] = $fileScope; $file['owner_title'] = $owner['title'] ?? '';
            $match = $folder === 'all' || ($folder === 'unfiled' && $file['folder'] === '') || $file['group'] === $folder ||
                (isset($labels[$folder], $labels[$file['folder']]) && ($file['folder'] === $folder || str_starts_with($labels[$file['folder']], $labels[$folder] . ' / ')));
            if (!$match || ($scope !== '' && $fileScope !== $scope)) continue;
            $searchMatch = self::contains($file['label'] . ' ' . $file['path'] . ' ' . ($owner['title'] ?? ''), $options['q']);
            if ($searchMatch) $matchingFiles[] = $file;
            if ($fileScope === null) continue;
            if (!isset($directories[$fileScope])) {
                $identity = MediaOwners::parse($fileScope);
                $noun = ['products' => 'Produkt', 'pages' => 'Stránka', 'posts' => 'Článek'][$identity['group']];
                $directories[$fileScope] = $identity + ['scope' => $fileScope, 'title' => $owner['title'] ?? $noun . ' bez aktuálního záznamu',
                    'owner' => $owner, 'count' => 0, 'bytes' => 0, 'created' => 0, 'thumb' => $file['thumb'], 'matches' => false];
            }
            $entry = &$directories[$fileScope];
            $entry['count']++; $entry['bytes'] += $file['bytes']; $entry['created'] = max($entry['created'], $file['created']);
            $entry['matches'] = $entry['matches'] || $searchMatch;
            if (($owner['image_path'] ?? '') === $file['path']) $entry['thumb'] = $file['thumb'];
            unset($entry);
        }
        $active = $scope !== '' ? ($directories[$scope] ?? null) : null;
        $directories = array_values(array_filter($directories, static fn (array $entry): bool => $entry['matches']));
        $sort = $options['sort'];
        $compare = static function (array $a, array $b) use ($sort): int {
            $nameA = $a['title'] ?? $a['label']; $nameB = $b['title'] ?? $b['label'];
            $first = match ($sort) { 'name' => strnatcasecmp(self::fold($nameA), self::fold($nameB)),
                'oldest' => $a['created'] <=> $b['created'], 'size' => $b['bytes'] <=> $a['bytes'], default => $b['created'] <=> $a['created'] };
            return $first ?: strcmp($a['scope'] ?? $a['path'], $b['scope'] ?? $b['path']);
        };
        usort($matchingFiles, $compare); usort($directories, $compare);
        $entries = $options['view'] === 'folders' ? $directories : $matchingFiles;
        // Clamp after filtering so an old bookmark cannot strand users on an empty page.
        $offset = count($entries) === 0 ? 0 : min($options['offset'], intdiv(count($entries) - 1, $limit) * $limit);
        return ['files' => $options['view'] === 'folders' ? [] : array_slice($matchingFiles, $offset, $limit),
            'directories' => $options['view'] === 'folders' ? array_slice($directories, $offset, $limit) : [],
            'active' => $active, 'total' => count($entries), 'photoTotal' => count($matchingFiles), 'folders' => $labels,
            'counts' => $counts, 'bytes' => $allBytes, 'offset' => $offset];
    }

    /** Resolve bulk folder selections on the server; never trust client-supplied file counts. */
    public static function selectedPaths(array $files, array $scopes): array
    {
        if (count($scopes) > 200) throw new InvalidArgumentException('Vyber nejvýše 200 složek.');
        foreach ($scopes as $scope) { if (!is_string($scope)) throw new InvalidArgumentException('Neplatný výběr složek.'); MediaOwners::parse($scope); }
        $scopes = array_fill_keys($scopes, true); $paths = []; $found = [];
        foreach ($files as $file) {
            $scope = MediaOwners::scope($file['path']);
            if ($scope !== null && isset($scopes[$scope])) { $paths[] = $file['path']; $found[$scope] = true; }
        }
        if (array_diff_key($scopes, $found) !== []) throw new InvalidArgumentException('Vybraná složka už neobsahuje obrázky. Obnov přehled.');
        return $paths;
    }

    private static function contains(string $value, string $search): bool
    {
        return $search === '' || str_contains(self::fold($value), self::fold($search));
    }

    private static function fold(string $value): string
    {
        return strtolower(strtr($value, ['á'=>'a','Á'=>'a','č'=>'c','Č'=>'c','ď'=>'d','Ď'=>'d','é'=>'e','É'=>'e','ě'=>'e','Ě'=>'e',
            'í'=>'i','Í'=>'i','ň'=>'n','Ň'=>'n','ó'=>'o','Ó'=>'o','ř'=>'r','Ř'=>'r','š'=>'s','Š'=>'s','ť'=>'t','Ť'=>'t','ú'=>'u','Ú'=>'u','ů'=>'u','Ů'=>'u','ý'=>'y','Ý'=>'y','ž'=>'z','Ž'=>'z']));
    }
}
