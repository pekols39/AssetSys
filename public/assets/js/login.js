document.addEventListener("DOMContentLoaded", () => {

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

            if (result.success) {

                window.location.href =
                    result.redirect || "dashboard/";

                return;

            }

            const message =
                result.message ||
                "Invalid username or password.";

            if (typeof showError === "function") {

                await showError(
                    message,
                    "Login Failed"
                );

            } else {

                alert(message);

            }

        } catch (error) {

            console.error(
                "Login request failed:",
                error
            );

            if (typeof showError === "function") {

                await showError(
                    "Unable to connect to the server.",
                    "Connection Error"
                );

            } else {

                alert(
                    "Unable to connect to the server."
                );

            }

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