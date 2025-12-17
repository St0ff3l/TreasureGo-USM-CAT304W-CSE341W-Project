/**
 * TreasureGo - Global Authentication Modal Component
 * * A standalone, reusable component that prompts unauthenticated users to log in.
 * It dynamically injects necessary CSS and HTML into the DOM upon initialization.
 * * Usage:
 * AuthModal.show();  // Initializes and opens the modal
 * AuthModal.close(); // Closes the modal
 */
const AuthModal = {
    /**
     * The HTML structure for the modal dialog.
     * Contains the icon, message, and action buttons.
     */
    htmlContent: `
        <dialog id="globalLoginDialog" class="tg-auth-modal">
            <div style="font-size: 40px; margin-bottom: 10px;">🔒</div>
            <h3 style="margin-bottom:10px; color: #1F2937; font-size: 1.2rem; font-family: 'Poppins', sans-serif;">Login Required</h3>
            <p style="margin-bottom:25px; color:#6B7280; font-size: 0.95rem; line-height: 1.5; font-family: 'Poppins', sans-serif;">
                You need to log in to access this feature.
            </p>
            <div style="display:flex; gap:10px; justify-content:center;">
                <button onclick="AuthModal.close()"
                        style="padding:10px 20px; border:1px solid #E5E7EB; background:white; color: #374151; border-radius:12px; cursor:pointer; font-weight: 600;">
                    Cancel
                </button>
                <button onclick="window.location.href='/Module_User_Account_Management/pages/login.php'" 
                        style="padding:10px 20px; border:none; background:#4F46E5; color:white; border-radius:12px; cursor:pointer; font-weight: 600;">
                    Go to Login
                </button>
            </div>
        </dialog>
    `,

    /**
     * Initializes the component.
     * Checks if the modal already exists in the DOM; if not, injects the styles and HTML.
     * This method is idempotent (safe to call multiple times).
     */
    init: function() {
        // Prevent duplicate injection
        if (document.getElementById('globalLoginDialog')) return;

        // 1. Create and inject CSS Styles
        const style = document.createElement('style');
        style.innerHTML = `
            /* Backdrop styling (blur effect) */
            .tg-auth-modal::backdrop { 
                background: rgba(0, 0, 0, 0.4); 
                backdrop-filter: blur(4px); 
            }

            /* Modal container styling */
            .tg-auth-modal {
                /* Positioning: Visual Center */
                position: fixed;     /* Fix position relative to the viewport */
                top: 30%;            /* Position at 30% from the top (Visual Gold Mean) */
                bottom: auto;        /* Disable default vertical centering logic */
                left: 0; 
                right: 0;            /* Stretch horizontally to allow margin auto to work */
                margin: 0 auto;      /* Center horizontally */
                
                /* Appearance */
                border-radius: 24px; 
                padding: 30px; 
                box-shadow: 0 20px 50px rgba(0,0,0,0.15); 
                text-align: center; 
                width: 320px; 
                border: none; 
                outline: none;
                
                /* Animation */
                animation: tgSlideDown 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            }

            /* Slide down animation keyframes */
            @keyframes tgSlideDown { 
                from { transform: translateY(-30px); opacity: 0; } 
                to { transform: translateY(0); opacity: 1; } 
            }
        `;
        document.head.appendChild(style);

        // 2. Inject HTML Template into body
        document.body.insertAdjacentHTML('beforeend', this.htmlContent);
    },

    /**
     * Triggers the initialization and displays the modal using the native showModal() API.
     */
    show: function() {
        this.init(); // Ensure dependencies are loaded
        const dialog = document.getElementById('globalLoginDialog');
        if (dialog) {
            dialog.showModal();
        }
    },

    /**
     * Closes the modal dialog.
     */
    close: function() {
        const dialog = document.getElementById('globalLoginDialog');
        if (dialog) {
            dialog.close();
        }
    }
};