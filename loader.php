<?php
/*
Plugin Name: Saat Captcha Pro
Version: 1.0.0
*/

if (!defined('ABSPATH')) exit;

define('SAAT_CAPTCHA_LOCK_MINUTES', 0.1);

/**
 * SHORTCODE
 */
add_shortcode('saat_captcha', function () {

    $token = wp_generate_uuid4();
    $hour = rand(0, 11);

    set_transient("captcha_$token", ['hour' => $hour], 600);

    ob_start(); ?>

<style>
.saat-captcha {
    font-family: Arial, sans-serif;
}

.saat-captcha .lock-timer {
    margin-top: 8px;
    font-size: 14px;
    color: #333;
    font-weight: bold;
}

.saat-captcha .canvas-wrap {
    padding: 10px 0;
    width: 100%;
    clear: both;
}

.saat-captcha canvas {
    border-radius: 50%;
    background-color: #fff;
    transition: transform 0.2s ease, opacity 0.2s ease, filter 0.2s ease;
}

.saat-captcha .clock-user {
    cursor: pointer;
}

.saat-captcha.is-locked .clock-user {
    cursor: not-allowed;
}

.saat-captcha.is-valid .clock-user {
    cursor: default;
}

/* HOVER ONLY ACTIVE STATE */
.saat-captcha:not(.is-locked):not(.is-valid) .clock-user:hover {
    transform: scale(1.03);
}

/* HARD BLOCK (GARANTİ) */
.saat-captcha.is-locked .clock-user,
.saat-captcha.is-valid .clock-user {
    transform: none !important;
    pointer-events: none;
}

/* VISUAL STATES */
.saat-captcha.is-locked canvas {
    opacity: 0.4;
    filter: grayscale(1);
}

.saat-captcha.is-valid canvas {
    opacity: 1;
    filter: none;
}

.saat-captcha.is-locked .lock-timer {
    color: red;
}

.saat-captcha.is-valid .lock-timer {
    color: green;
}
</style>

<div class="saat-captcha" data-token="<?= esc_attr($token) ?>">

    <div class="canvas-wrap">
        <canvas width="100" height="100" class="clock-target"></canvas>
        <canvas width="100" height="100" class="clock-user"></canvas>
    </div>

    <input type="hidden" name="captcha_token" class="token-field">
    <input type="hidden" name="captcha_hour" class="hour-hidden">
    <input type="hidden" name="captcha_moves" class="moves-hidden">

    <div class="lock-timer"></div>
</div>

<script>
document.querySelectorAll(".saat-captcha").forEach(el => {

    const token = el.dataset.token;

    const targetCanvas = el.querySelector(".clock-target");
    const userCanvas   = el.querySelector(".clock-user");

    const tctx = targetCanvas.getContext("2d");
    const uctx = userCanvas.getContext("2d");

    const size = 100;
    const c = size / 2;

    let selectedHour = null;
    let moveCount = 0;
    let dragging = false;

    let locked = false;
    let targetHour = null;

    function setTimerMessage(text, type="info"){
        const msg = el.querySelector(".lock-timer");
        msg.innerText = text;
        msg.style.color =
            type === "success" ? "green" :
            type === "error" ? "red" :
            "#333";
    }

    function drawClock(ctx, hour, type="normal"){

        ctx.setTransform(1,0,0,1,0,0);
        ctx.clearRect(0,0,size,size);

        let color = "#111";
        if(type==="success") color="green";
        if(type==="error") color="red";

        ctx.strokeStyle = color;
        ctx.lineWidth = 2;

        ctx.beginPath();
        ctx.arc(c,c,45,0,Math.PI*2);
        ctx.stroke();

        for(let i=0;i<12;i++){
            let a=i*30;
            let x1=c+35*Math.sin(a*Math.PI/180);
            let y1=c-35*Math.cos(a*Math.PI/180);
            let x2=c+42*Math.sin(a*Math.PI/180);
            let y2=c-42*Math.cos(a*Math.PI/180);

            ctx.beginPath();
            ctx.moveTo(x1,y1);
            ctx.lineTo(x2,y2);
            ctx.stroke();
        }

        if(hour === null){
            ctx.font = "bold 34px sans-serif";
            ctx.fillStyle = "#666";
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.fillText("?", c, c);
            return;
        }

        let angle = hour * 30;

        ctx.beginPath();
        ctx.lineWidth = 4;
        ctx.moveTo(c,c);
        ctx.lineTo(
            c + 25 * Math.sin(angle*Math.PI/180),
            c - 25 * Math.cos(angle*Math.PI/180)
        );
        ctx.stroke();
    }

    function resetUserClock(){
        selectedHour = null;
        drawClock(uctx, null);
    }

    let lockInterval = null;
    let lockEndTime = null;

    function startLockTimer(seconds){

        locked = true;

        // 🔥 STATE SYNC (KRİTİK FIX)
        el.classList.add("is-locked");
        el.classList.remove("is-valid");

        resetUserClock();

        targetHour = null;
        drawClock(tctx, null);

        targetCanvas.style.opacity = "0.3";
        userCanvas.style.opacity = "0.3";

        lockEndTime = Date.now() + seconds * 1000;

        if(lockInterval) clearInterval(lockInterval);

        function tick(){

            const diff = lockEndTime - Date.now();

            if(diff <= 0){

                clearInterval(lockInterval);
                locked = false;

                el.classList.remove("is-locked");

                targetCanvas.style.opacity = "1";
                userCanvas.style.opacity = "1";

                setTimerMessage("Artık deneyebilirsiniz", "success");

                fetch("<?= admin_url('admin-ajax.php'); ?>", {
                    method: "POST",
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: "action=refresh_clock&token=" + token
                })
                .then(r => r.json())
                .then(d => {
                    targetHour = d.hour;
                    drawClock(tctx, targetHour);
                });

                return;
            }

            const m = Math.floor(diff / 60000);
            const s = Math.floor((diff % 60000) / 1000);

            setTimerMessage(
                `Kalan Süre: ${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`,
                "error"
            );
        }

        tick();
        lockInterval = setInterval(tick, 1000);
    }

    fetch("<?= admin_url('admin-ajax.php'); ?>?action=get_clock&token="+token)
    .then(r=>r.json())
    .then(d=>{
        if(d.hour !== undefined){
            targetHour = d.hour;
            drawClock(tctx, targetHour);
        }
    });

    resetUserClock();

    function updateClock(e){

        if(locked || el.dataset.valid === "1") return;

        const rect = userCanvas.getBoundingClientRect();
        const x = e.clientX - rect.left - c;
        const y = e.clientY - rect.top - c;

        let angle = Math.atan2(x, -y) * (180/Math.PI);
        if(angle < 0) angle += 360;

        selectedHour = Math.floor((angle+15)/30)%12;
        moveCount++;

        drawClock(uctx, selectedHour);
    }

    userCanvas.addEventListener("mousedown", e=>{
        if(locked || el.dataset.valid === "1") return;

        setTimerMessage("", "info");
        dragging = true;
        updateClock(e);
    });

    userCanvas.addEventListener("mousemove", e=>{
        if(dragging) updateClock(e);
    });

    window.addEventListener("mouseup", ()=>{

        if(!dragging) return;
        dragging = false;

        fetch("<?= admin_url('admin-ajax.php'); ?>",{
            method:"POST",
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:
                "action=check_clock"+
                "&token="+token+
                "&hour="+selectedHour+
                "&moves="+moveCount
        })
        .then(r=>r.json())
        .then(res=>{

            if(res.locked){
                startLockTimer(res.remaining || 60);
                setTimerMessage("Kilit aktif", "error");
                return;
            }

            if(res.success){

                el.dataset.valid = "1";
                el.classList.add("is-valid");

                locked = true;

                el.querySelector(".token-field").value = token;
                el.querySelector(".hour-hidden").value = selectedHour;
                el.querySelector(".moves-hidden").value = moveCount;

                drawClock(uctx, selectedHour, "success");
                userCanvas.style.pointerEvents = "none";

                setTimerMessage(res.message, "success");

            } else {

                drawClock(uctx, selectedHour, "error");
                setTimerMessage(res.message, "error");
            }
        });
    });

    fetch("<?= admin_url('admin-ajax.php'); ?>?action=captcha_status")
    .then(r=>r.json())
    .then(res=>{
        if(res.locked){
            startLockTimer(res.remaining || 60);
        }
    });

});

document.addEventListener("submit", function(e){

    const c = document.querySelector(".saat-captcha");

    if(!c) return;

    if(c.dataset.valid !== "1"){
        e.preventDefault();
        alert("Captcha doğrulanmadı");
    }
});
</script>

<?php
return ob_get_clean();
});

/**
 * AJAX FUNCTIONS (unchanged)
 */
add_action('wp_ajax_get_clock','get_clock');
add_action('wp_ajax_nopriv_get_clock','get_clock');

function get_clock(){
    $token = sanitize_text_field($_GET['token'] ?? '');
    $data = get_transient("captcha_$token");

    if(!$data){
        wp_send_json_error();
    }

    wp_send_json(['hour'=>$data['hour']]);
}

add_action('wp_ajax_refresh_clock','refresh_clock');
add_action('wp_ajax_nopriv_refresh_clock','refresh_clock');

function refresh_clock(){

    $token = sanitize_text_field($_POST['token'] ?? '');

    if(!$token){
        wp_send_json_error();
    }

    $hour = rand(0,11);

    set_transient("captcha_$token", ['hour'=>$hour], 600);

    wp_send_json(['hour'=>$hour]);
}

add_action('wp_ajax_check_clock','check_clock');
add_action('wp_ajax_nopriv_check_clock','check_clock');

function check_clock(){

    $token = sanitize_text_field($_POST['token'] ?? '');
    $hour  = intval($_POST['hour'] ?? -1);
    $moves = intval($_POST['moves'] ?? 0);

    $data = get_transient("captcha_$token");

    if(!$data){
        wp_send_json(['success'=>false,'message'=>'Süre doldu']);
    }

    $ip = $_SERVER['REMOTE_ADDR'];

    $lock_key = "captcha_lock_ip_$ip";
    $fail_key = "captcha_fail_ip_$ip";

    $lock_until = get_transient($lock_key);

    if($lock_until && $lock_until > time()){
        wp_send_json([
            'locked'=>true,
            'remaining'=>max(0, $lock_until - time())
        ]);
    }

    if($moves < 1){
        wp_send_json(['success'=>false,'message'=>'Bot şüphesi']);
    }

    if($hour == $data['hour']){
        set_transient("captcha_passed_$token",1,600);
        delete_transient($fail_key);
        wp_send_json(['success'=>true,'message'=>'Doğru!']);
    }

    $fails = (int)get_transient($fail_key);
    $fails++;

    if($fails >= 3){

        $seconds = SAAT_CAPTCHA_LOCK_MINUTES * 60;
        $lock_until = time() + $seconds;

        set_transient($lock_key, $lock_until, $seconds);
        delete_transient($fail_key);

        wp_send_json([
            'locked'=>true,
            'remaining'=>$seconds,
            'message'=>'Kilit aktif'
        ]);
    }

    set_transient($fail_key, $fails, 300);

    wp_send_json([
        'success'=>false,
        'message'=>"Eşleşmedi ({$fails}/3)"
    ]);
}

add_action('wp_ajax_captcha_status','captcha_status');
add_action('wp_ajax_nopriv_captcha_status','captcha_status');

function captcha_status(){

    $ip = $_SERVER['REMOTE_ADDR'];
    $lock_key = "captcha_lock_ip_$ip";

    $lock_until = get_transient($lock_key);

	/* SÜRE SIFIRLAMA */

	//delete_transient($lock_key);
	//wp_send_json(['locked'=>false]);

    if(!$lock_until){
        wp_send_json(['locked'=>false]);
    }

    if($lock_until <= time()){
        delete_transient($lock_key);
        wp_send_json(['locked'=>false]);
    }

    wp_send_json([
        'locked'=>true,
        'remaining'=>max(0, $lock_until - time())
    ]);
}

function saat_captcha_verify(){

    if(empty($_POST)) return false;

    $token = sanitize_text_field($_POST['captcha_token'] ?? '');

    if(!$token) return false;

    $passed = get_transient("captcha_passed_$token");

    if(!$passed) return false;

    delete_transient("captcha_passed_$token");
    delete_transient("captcha_$token");

    return true;
}

add_action('login_form', function(){ echo do_shortcode('[saat_captcha]'); });
add_action('register_form', function(){ echo do_shortcode('[saat_captcha]'); });
add_action('lostpassword_form', function(){ echo do_shortcode('[saat_captcha]'); });
add_action('comment_form', function(){ echo do_shortcode('[saat_captcha]'); });
