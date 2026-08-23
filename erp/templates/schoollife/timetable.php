<?php
/**
 * Module 9 : emploi du temps d'une classe, une colonne par jour.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $classrooms, $subjects, $teachers, $rooms
 * @var array<string, array<int, array<string, mixed>>> $byDay
 * @var array<string, string> $days
 * @var string $classroomId
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Emplois du temps';
?>
<h1>Emplois du temps</h1>
<p class="subtitle">
    Un creneau est refuse s il place l enseignant, la salle ou la classe en
    double : le conflit se detecte a la saisie, pas sur le terrain.
</p>

<form method="get" action="/timetable" class="filters">
    <select name="classroom">
        <option value="">Choisir une classe...</option>
        <?php foreach ($classrooms as $classroom) : ?>
            <option value="<?= $this->e($classroom['id']) ?>" <?= $classroomId === $classroom['id'] ? 'selected' : '' ?>>
                <?= $this->e($classroom['name']) ?> (<?= $this->e($classroom['level_name']) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button--secondary">Afficher</button>
</form>

<?php if ($classroomId === '') : ?>
    <div class="card"><p class="muted">Choisissez une classe pour voir son emploi du temps.</p></div>
<?php else : ?>
    <div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
        <?php foreach ($days as $code => $label) : ?>
            <div class="cards__item">
                <div class="cards__title"><?= $this->e($label) ?></div>

                <?php if ($byDay[$code] === []) : ?>
                    <p class="cards__text">Aucun cours.</p>
                <?php else : ?>
                    <?php foreach ($byDay[$code] as $slot) : ?>
                        <div style="padding:.6rem 0;border-top:1px solid rgba(255,255,255,.06)">
                            <div class="font-mono" style="font-size:.72rem;color:#c8ff00">
                                <?= $this->e($slot['start_time']) ?> — <?= $this->e($slot['end_time']) ?>
                            </div>
                            <div style="font-weight:600;font-size:.88rem"><?= $this->e($slot['subject_name']) ?></div>
                            <div class="cards__text" style="margin:0">
                                <?= $this->e($slot['last_name'].' '.$slot['first_name']) ?>
                                <?php if ($slot['room_name']) : ?>
                                    &middot; <?= $this->e($slot['room_name']) ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($rbac->allows('timetables:delete')) : ?>
                                <form method="post" action="/timetable/<?= $this->e($slot['id']) ?>/delete" class="inline-form">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <button type="submit" class="link-button">Retirer</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($rbac->allows('timetables:create')) : ?>
        <form method="post" action="/timetable/<?= $this->e($classroomId) ?>" class="card" style="margin-top:1.5rem">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Ajouter un creneau</h2>

            <div class="grid-2">
                <div class="field">
                    <label for="subject_id">Matiere *</label>
                    <select id="subject_id" name="subject_id" required>
                        <option value="">Choisir...</option>
                        <?php foreach ($subjects as $subject) : ?>
                            <option value="<?= $this->e($subject['id']) ?>"><?= $this->e($subject['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="teacher_id">Enseignant *</label>
                    <select id="teacher_id" name="teacher_id" required>
                        <option value="">Choisir...</option>
                        <?php foreach ($teachers as $teacher) : ?>
                            <option value="<?= $this->e($teacher['id']) ?>">
                                <?= $this->e($teacher['last_name'].' '.$teacher['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="day_of_week">Jour *</label>
                    <select id="day_of_week" name="day_of_week" required>
                        <?php foreach ($days as $code => $label) : ?>
                            <option value="<?= $this->e($code) ?>"><?= $this->e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="room_id">Salle</label>
                    <select id="room_id" name="room_id">
                        <option value="">Aucune</option>
                        <?php foreach ($rooms as $room) : ?>
                            <option value="<?= $this->e($room['id']) ?>"><?= $this->e($room['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="start_time">Debut *</label>
                    <input id="start_time" name="start_time" type="time" required value="08:00">
                </div>
                <div class="field">
                    <label for="end_time">Fin *</label>
                    <input id="end_time" name="end_time" type="time" required value="10:00">
                </div>
            </div>

            <button type="submit" class="button">Ajouter le creneau</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
