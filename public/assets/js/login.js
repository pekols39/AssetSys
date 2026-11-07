document.addEventListener("DOMContentLoaded", () => {

    // ==========================================================
    // SweetAlert2 Login Notifications
    // ==========================================================

    const showLoginAlert = async (
        title,
        message,
        icon = "error"
    ) => {

        if (window.Swal && typeof window.Swal.fire === "function") {

            return await Swal.fire({
                icon: icon,
                title: title,
                text: message,
                confirmButtonText: "Okay",
                confirmButtonColor: "#007472",
                allowOutsideClick: false,
                customClass: {
                    confirmButton: "px-4"
                }
            });

        }

        // Fallback only if the SweetAlert2 CDN fails to load.
        console.error("SweetAlert2 is unavailable:", title, message);
        window.alert(`${title}\n\n${message}`);

    };


    // ==========================================================
    // Toggle Password
    // ==========================================================

    const togglePassword = document.getElementById("togglePassword");
    const password = document.getElementById("password");
    const toggleIcon = document.getElementById("toggleIcon");

    if (togglePassword && password && toggleIcon) {

        togglePassword.addEventListener("click", () => {

            const type = password.type === "password"
                ? "text"
                : "password";

            password.type = type;

            toggleIcon.classList.toggle("fa-eye");
            toggleIcon.classList.toggle("fa-eye-slash");

            togglePassword.setAttribute(
                "aria-label",
                type === "password"
                    ? "Show password"
                    : "Hide password"
            );

        });

    }


    // ==========================================================
    // Login Form
    // ==========================================================

    const loginForm = document.getElementById("loginForm");

    if (!loginForm) {
        return;
    }

    const loginBtn = document.getElementById("loginBtn");
    const btnText = document.getElementById("btnText");
    const btnLoader = document.getElementById("btnLoader");

    loginForm.addEventListener("submit", async (event) => {

        event.preventDefault();

        if (!loginBtn) {
            return;
        }

        loginBtn.disabled = true;

        if (btnLoader) {
            btnLoader.classList.remove("d-none");
        }

        if (btnText) {
            btnText.textContent = "Signing In...";
        }

        const formData = new FormData(loginForm);

        try {

            const response = await fetch(
                "auth/login_process.php",
                {
                    method: "POST",
                    body: formData,
                    credentials: "same-origin",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest"
                    }
                }
            );

            const result = await response.json();

            // Successful login
            if (response.ok && result.success) {

                window.location.href =
                    result.redirect || "dashboard/";

                return;

            }

            // Failed login
            await showLoginAlert(
                "Login Failed",
                result.message ||
                "Invalid username or password.",
                "error"
            );

        } catch (error) {

            console.error("Login request failed:", error);

            await showLoginAlert(
                "Connection Error",
                "Unable to connect to the server. Please check your connection and try again.",
                "warning"
            );

        } finally {

            loginBtn.disabled = false;

            if (btnLoader) {
                btnLoader.classList.add("d-none");
            }

            if (btnText) {
                btnText.textContent = "Sign In";
            }

        }

    });

});
