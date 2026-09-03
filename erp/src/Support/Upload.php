<?php

declare(strict_types=1);

namespace Scholaris\Support;

/**
 * Fichier recu par televersement.
 *
 * Les fichiers n'entrent pas par Request : celle-ci est construite a partir de
 * tableaux, ce qui la rend testable, alors qu'un televersement passe par
 * $_FILES et par un fichier temporaire du serveur. Les isoler ici garde
 * Request pure et concentre au meme endroit les verifications qui protegent
 * reellement : type declare, taille, et surtout nom de stockage genere plutot
 * que repris du navigateur.
 */
final class Upload
{
    /** Types acceptes, associes a l'extension imposee au stockage. */
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    private const MAX_BYTES = 10485760;

    private string $originalName;

    private string $temporaryPath;

    private string $mimeType;

    private int $size;

    private function __construct(string $originalName, string $temporaryPath, string $mimeType, int $size)
    {
        $this->originalName = $originalName;
        $this->temporaryPath = $temporaryPath;
        $this->mimeType = $mimeType;
        $this->size = $size;
    }

    /**
     * Lit un televersement dans $_FILES, ou null s'il n'y en a pas.
     *
     * @param  array<string, mixed>|null  $files  injectable pour les tests
     */
    public static function fromGlobals(string $field, ?array $files = null): ?self
    {
        $files ??= $_FILES;
        $entry = $files[$field] ?? null;

        if (! is_array($entry) || ! isset($entry['tmp_name'])) {
            return null;
        }

        if ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        return new self(
            (string) ($entry['name'] ?? 'document'),
            (string) $entry['tmp_name'],
            (string) ($entry['type'] ?? 'application/octet-stream'),
            (int) ($entry['size'] ?? 0)
        );
    }

    /**
     * Message d'erreur, ou null si le fichier est acceptable.
     *
     * Le type est relu sur le contenu quand l'extension finfo est disponible :
     * l'en-tete envoye par le navigateur est declaratif, donc falsifiable, et
     * s'y fier reviendrait a laisser choisir le type par celui qui depose.
     */
    public function rejectionReason(): ?string
    {
        if ($this->size <= 0) {
            return 'Le fichier est vide.';
        }

        if ($this->size > self::MAX_BYTES) {
            return 'Le fichier depasse 10 Mo.';
        }

        $type = $this->detectedType();

        if (! isset(self::ALLOWED[$type])) {
            return 'Format non accepte. Formats admis : PDF, image, Word, Excel, texte.';
        }

        return null;
    }

    /** Type reel du contenu, avec repli sur le type declare. */
    public function detectedType(): string
    {
        if (function_exists('finfo_open') && is_readable($this->temporaryPath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $this->temporaryPath);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $this->mimeType;
    }

    /**
     * Nom sous lequel le fichier est range.
     *
     * Genere, jamais derive du nom d'origine : un fichier appele
     * « ../../config.php » ou « facture.php » ne doit pouvoir ni sortir du
     * dossier, ni etre execute s'il venait a etre servi.
     */
    public function storedName(): string
    {
        $extension = self::ALLOWED[$this->detectedType()] ?? 'bin';

        return bin2hex(random_bytes(16)).'.'.$extension;
    }

    public function originalName(): string
    {
        // Le nom d'origine n'est conserve que pour etre affiche et propose au
        // telechargement ; il ne sert jamais a construire un chemin.
        return mb_substr(basename($this->originalName), 0, 255);
    }

    public function size(): int
    {
        return $this->size;
    }

    /** Deplace le fichier a sa place definitive et rend sa somme de controle. */
    public function moveTo(string $destination): string
    {
        $directory = dirname($destination);

        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }

        // move_uploaded_file refuse un chemin qui ne vient pas d'un
        // televersement : c'est la garantie qu'on ne deplace pas un fichier
        // arbitraire du serveur. Le repli sert aux tests, ou le fichier est
        // pose a la main.
        if (is_uploaded_file($this->temporaryPath)) {
            move_uploaded_file($this->temporaryPath, $destination);
        } else {
            rename($this->temporaryPath, $destination);
        }

        $checksum = hash_file('sha256', $destination);

        return $checksum === false ? '' : $checksum;
    }
}
