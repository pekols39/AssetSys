<?php

declare(strict_types=1);
?>

</div>

</main>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Global JS -->
<script src="<?= e(asset('js/app.js')) ?>"></script>

<script src="<?= e(asset('js/sidebar.js')) ?>"></script>

<?php if (!empty($pageScript)): ?>

    <script src="<?= e(asset($pageScript)) ?>"></script>

<?php endif; ?>

<?php if (!empty($pageScripts)): ?>

    <?php foreach ($pageScripts as $script): ?>

        <?php
        $scriptUrl = str_starts_with(
            $script,
            'http://'
        ) || str_starts_with(
            $script,
            'https://'
        )
            ? $script
            : asset($script);
        ?>

        <script src="<?= e($scriptUrl) ?>"></script>

    <?php endforeach; ?>

<?php endif; ?>

</body>

</html>