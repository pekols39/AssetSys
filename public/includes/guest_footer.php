<?php

declare(strict_types=1);
?>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Global JS -->
<script src="<?= e(asset('js/app.js')) ?>"></script>

<?php if (!empty($pageScript)): ?>

    <script src="<?= e(asset($pageScript)) ?>"></script>

<?php endif; ?>

</body>
</html>