<?php
/**
 * Certificat de scolarite imprimable — la demande la plus frequente du
 * secretariat. Genere a la volee depuis l'inscription active, sans PDF ni
 * dependance externe : la mise en page imprimable suffit.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $student
 * @var array<string, mixed> $enrollment
 * @var string $tenantName
 * @var string $issuedAt
 */
$this->extends('layouts.app');
$title = 'Certificat de scolarite';
?>
<div class="page-header">
    <div>
        <h1>Certificat de scolarite</h1>
        <p class="subtitle"><?= $this->e($student['last_name'].' '.$student['first_name']) ?></p>
    </div>
    <div class="form-actions" style="margin:0">
        <button type="button" class="button" onclick="window.print()">Imprimer / PDF</button>
        <a class="button--secondary" href="/students/<?= $this->e($student['id']) ?>">Retour</a>
    </div>
</div>

<div class="card" style="max-width:44rem;margin:0 auto;line-height:1.7">
    <div style="text-align:center;margin-bottom:2rem">
        <div class="font-display" style="font-weight:800;font-size:1.2rem"><?= $this->e($tenantName) ?></div>
        <div class="muted" style="text-transform:uppercase;letter-spacing:.08em;font-size:.75rem;margin-top:.5rem">
            Certificat de scolarite
        </div>
    </div>

    <p>
        Je soussigne(e), responsable de l etablissement <strong><?= $this->e($tenantName) ?></strong>,
        certifie que :
    </p>

    <p style="margin-left:1.5rem">
        <strong><?= $this->e($student['last_name'].' '.$student['first_name']) ?></strong>
        (matricule <?= $this->e($student['matricule']) ?>),
        ne(e) le <?= $student['date_of_birth'] ? $this->date($student['date_of_birth']) : '-' ?>
        <?= $student['place_of_birth'] ? 'a '.$this->e($student['place_of_birth']) : '' ?>,
    </p>

    <p>
        est regulierement inscrit(e) dans notre etablissement en classe de
        <strong><?= $this->e($enrollment['classroom_name']) ?></strong>
        au titre de l annee academique <strong><?= $this->e($enrollment['year_label']) ?></strong>.
    </p>

    <p>
        En foi de quoi, ce certificat est delivre a l interesse(e) pour servir
        et valoir ce que de droit.
    </p>

    <div style="display:flex;justify-content:space-between;margin-top:3rem">
        <div class="muted">Fait le <?= $this->date($issuedAt) ?></div>
        <div style="text-align:center">
            <div class="muted" style="margin-bottom:2.5rem">Le chef d etablissement</div>
            <div class="muted" style="font-size:.72rem">(signature et cachet)</div>
        </div>
    </div>
</div>
