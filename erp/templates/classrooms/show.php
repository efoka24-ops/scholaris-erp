<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $classroom
 * @var array<string, mixed>|null $level
 * @var array<int, array<string, mixed>> $students
 * @var \Scholaris\Auth\Rbac $rbac
 */
$this->extends('layouts.app');
$title = (string) $classroom['name'];
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($classroom['name']) ?></h1>
        <p class="subtitle">
            <?= $this->e($level['name'] ?? '') ?>
            &middot; <span class="badge"><?= $this->e($classroom['section']) ?></span>
            &middot; <?= $this->number(count($students)) ?> / <?= $this->number($classroom['capacity']) ?> eleves
        </p>
    </div>
    <?php if ($rbac->allows('classrooms:update')) : ?>
        <a class="button button--secondary" href="/classrooms/<?= $this->e($classroom['id']) ?>/edit">Modifier</a>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Effectif de l annee en cours</h2>

    <?php if ($students === []) : ?>
        <p class="muted">Aucun eleve inscrit dans cette classe pour l annee en cours.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Matricule</th><th>Nom</th><th>Prenom</th><th>Sexe</th><th>Regime</th></tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student) : ?>
                <tr>
                    <td><a href="/students/<?= $this->e($student['id']) ?>"><?= $this->e($student['matricule']) ?></a></td>
                    <td><?= $this->e($student['last_name']) ?></td>
                    <td><?= $this->e($student['first_name']) ?></td>
                    <td><?= $this->e($student['gender'] === 'MALE' ? 'M' : 'F') ?></td>
                    <td>
                        <?= $this->e($student['regime']) ?>
                        <?php if ((int) $student['is_repeater'] === 1) : ?>
                            <span class="badge">redoublant</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
