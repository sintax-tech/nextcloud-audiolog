<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Shown by PageController::index to users outside the app's allowed groups.
 *
 * @var \OCP\IL10N $l
 */
?>
<div class="access-denied-container" style="padding: 40px; text-align: center;">
    <h2 style="color: var(--color-error);"><?php p($l->t('Acesso negado')); ?></h2>
    <p style="margin-top: 20px; color: var(--color-text-maxcontrast);">
        <?php p($l->t('Você não tem permissão para usar o Audiolog.')); ?>
    </p>
    <p style="color: var(--color-text-maxcontrast);">
        <?php p($l->t('Fale com o administrador se precisar de acesso.')); ?>
    </p>
</div>
