(function () {
    'use strict';

    const params = new URLSearchParams(
        window.location.search
    );

    if (params.get('success') !== '1') {
        return;
    }

    Swal.fire({
        icon: 'success',
        title: 'Account Updated',
        text: 'User account updated successfully.',
        confirmButtonText: 'OK',
        confirmButtonColor: '#007472'
    }).then(function () {
        params.delete('success');

        const queryString = params.toString();

        const cleanUrl =
            window.location.pathname
            + (queryString !== ''
                ? '?' + queryString
                : '');

        window.history.replaceState(
            {},
            document.title,
            cleanUrl
        );
    });
})();