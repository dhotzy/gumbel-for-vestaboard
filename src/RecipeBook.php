<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Mitgelieferte Rezepte (recipes/*.json): fertige Integrationen zum Hinzufügen mit einem Klick.
 * Texte dürfen zweisprachig sein ({"de": "…", "en": "…"}); geladen wird die Sprache der Oberfläche.
 */
final class RecipeBook
{
    public function __construct(private readonly string $directory = APP_ROOT . '/recipes')
    {
    }

    /** @return list<array{id: string, name: string, description: string, needs: string, recipe: array}> */
    public function forModule(string $module): array
    {
        return array_values(array_filter($this->all(), static fn (array $entry): bool => ($entry['recipe']['module'] ?? '') === $module));
    }

    /** @return list<array{id: string, name: string, description: string, needs: string, recipe: array}> */
    public function all(): array
    {
        $list = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $entry = $this->load(basename($file, '.json'));
            if ($entry !== null) {
                $list[] = $entry;
            }
        }
        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    /** @return ?array{id: string, name: string, description: string, needs: string, recipe: array} */
    public function load(string $id): ?array
    {
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $id) || !is_file($this->directory . '/' . $id . '.json')) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->directory . '/' . $id . '.json'), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'gumbel-integration') {
            return null;
        }
        $recipe = self::localize($data);
        return [
            'id' => $id,
            'name' => (string) ($recipe['name'] ?? $id),
            'description' => (string) ($recipe['description'] ?? ''),
            'needs' => (string) ($recipe['needs'] ?? ''),
            'recipe' => $recipe,
        ];
    }

    /** {"de": …, "en": …} → Text in der aktuellen Sprache (Englisch als Rückfall), auch in verschachtelten Listen */
    private static function localize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && !array_is_list($value) && array_diff(array_keys($value), ['de', 'en']) === []) {
            return $value[I18n::lang()] ?? $value['en'] ?? reset($value);
        }
        return array_map([self::class, 'localize'], $value);
    }
}
