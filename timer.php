<?php
session_start();

// 설정 불러오기 (JSON 파일 우선, 없으면 세션, 그것도 없으면 메인 페이지로)
$settings = null;

if (file_exists('timer_settings.json')) {
    $settings = json_decode(file_get_contents('timer_settings.json'), true);
} elseif (isset($_SESSION['timer_settings'])) {
    $settings = $_SESSION['timer_settings'];
}

if (!$settings) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['title']) ?> - 타이머</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
    <style>
        @keyframes pulse {
            0% { opacity: 0.6; transform: translateX(-50%) scale(1); }
            50% { opacity: 1; transform: translateX(-50%) scale(1.05); }
            100% { opacity: 0.6; transform: translateX(-50%) scale(1); }
        }
    </style>
</head>
<body class="timer-body">
    <div class="timer-container">
        <div class="timer-content">
            <h1 class="timer-title"><?= nl2br($settings['title']) ?></h1>
            
            <div class="timer-display-container">
                <div class="circular-progress">
            <svg class="progress-ring" viewBox="0 0 400 400">
                <circle class="progress-ring-background" cx="200" cy="200" r="160" />
                <circle class="progress-ring-circle" cx="200" cy="200" r="160" />
                <g class="progress-ring-ticks"></g>
            </svg>
                           <div class="timer-display" id="timerDisplay">
                               <?= sprintf('%02d:%02d', $settings['minutes'], isset($settings['seconds']) ? $settings['seconds'] : 0) ?>
                           </div>
                    <div class="music-info" id="musicInfo"></div>
                </div>
            </div>
            
            
            <div id="guideMessage" class="guide-message" style="display: none;">
                <span class="message-line"></span>
            </div>
        </div>
    </div>
    
    <?php 
    $music_url = isset($settings['online_music']) ? $settings['online_music'] : '';
    if (!empty($music_url)): 
        // CDN 직접 연결만 시도 (프록시 사용 안 함)
        $direct_url = $music_url;
    ?>
        <audio id="backgroundMusic" preload="auto">
            <!-- CDN 직접 연결만 시도 -->
            <source src="<?= htmlspecialchars($direct_url) ?>" type="audio/mpeg">
            브라우저가 오디오를 지원하지 않습니다.
        </audio>
        <script>
            console.log('원본 음악 URL:', <?= json_encode($music_url) ?>);
            console.log('CDN 직접 연결만 시도 (프록시 사용 안 함)');
            
            // CDN 직접 연결만 시도
            const backgroundMusic = document.getElementById('backgroundMusic');
            let attemptCount = 0;
            const maxAttempts = 2; // 직접 연결 2회만 시도
            
            function tryDirectAccess() {
                attemptCount++;
                console.log(`CDN 직접 연결 시도 ${attemptCount}/${maxAttempts}`);
                
                if (backgroundMusic) {
                    // 에러 발생 시 처리
                    backgroundMusic.addEventListener('error', function handleError() {
                        console.log(`시도 ${attemptCount} 실패`);
                        
                        if (attemptCount < maxAttempts) {
                            // 두 번째 시도: crossorigin 추가
                            this.removeEventListener('error', handleError);
                            console.log('crossorigin 속성 추가하여 재시도');
                            this.crossOrigin = 'anonymous';
                            this.load();
                        } else {
                            // 모든 시도 실패 - 음악 없이 진행
                            console.log('🚫 CDN 직접 연결 실패 - 음악 없이 진행 (서버 트래픽 0MB)');
                            this.remove(); // audio 요소 제거
                        }
                    });
                    
                    // 성공 시 로그
                    backgroundMusic.addEventListener('canplay', function() {
                        console.log('🎉 CDN 직접 연결 성공! 서버 트래픽 0MB');
                    });
                    
                    backgroundMusic.addEventListener('loadstart', function() {
                        console.log('음악 로드 시작:', this.src);
                    });
                }
            }
            
            // 첫 번째 시도 시작
            tryDirectAccess();
            
            // 음악 정보 표시
            const musicInfo = document.getElementById('musicInfo');
            if (musicInfo) {
                // JSON에서 음악 제목 찾기
                fetch('music_list.json')
                    .then(response => response.json())
                    .then(data => {
                        const currentSong = data.songs.find(song => song.url === <?= json_encode($music_url) ?>);
                        if (currentSong) {
                            musicInfo.textContent = '♪ ' + currentSong.title;
                        } else {
                            musicInfo.textContent = '♪ 배경음악 재생 중';
                        }
                    })
                    .catch(e => {
                        console.log('음악 정보 로드 실패:', e);
                        musicInfo.textContent = '♪ 배경음악 재생 중';
                    });
            }
        </script>
    <?php else: ?>
        <script>
            console.log('음악이 선택되지 않았습니다.');
            
            // 음악이 없을 때 정보 표시
            const musicInfo = document.getElementById('musicInfo');
            if (musicInfo) {
                musicInfo.textContent = '♪ 음악 없음';
            }
        </script>
    <?php endif; ?>
    
        <script>
            // 타이머 설정
            const TOTAL_SECONDS = <?= ($settings['minutes'] * 60) + (isset($settings['seconds']) ? $settings['seconds'] : 0) ?>;
            const END_MESSAGE = <?= json_encode($settings['end_message']) ?>;
        
        let remainingSeconds = TOTAL_SECONDS;
        let isRunning = true;
        let isPaused = false;
        let timerInterval;
        let blinkInterval;
        
        // DOM 요소
        const timerDisplay = document.getElementById('timerDisplay');
        const guideMessage = document.getElementById('guideMessage');
        const progressRing = document.querySelector('.progress-ring-circle');
        
        // 안내 메시지 관련 변수
        const GUIDE_MESSAGES = <?= json_encode(array_filter(explode("\n", $settings['end_message']), function($line) { return trim($line) !== ''; })) ?>;
        let currentMessageIndex = 0;
        let messageInterval = null;
        let isRestPeriod = false;
        // backgroundMusic은 위에서 이미 선언됨
        
        // 진행바 설정
        const radius = progressRing.r.baseVal.value;
        const circumference = radius * 2 * Math.PI;
        progressRing.style.strokeDasharray = `${circumference} ${circumference}`;
        progressRing.style.strokeDashoffset = 0; // 초기값을 0으로 설정 (100% 상태)
        
        // 진행바 구분선 생성 함수 (시계 스타일)
        function createProgressTicks() {
            const ticksContainer = document.querySelector('.progress-ring-ticks');
            const radius = 160;
            
            // 기존 구분선 제거
            ticksContainer.innerHTML = '';
            
            // 시계처럼 84개 틱 생성 (12개 큰 틱 + 72개 작은 틱)
            // 12개 큰 틱 사이에 각각 6개씩 작은 틱 = 12 + (12 × 6) = 84개
            for (let i = 0; i < 84; i++) {
                const angle = -90 + (i * (360 / 84)); // 약 4.29도씩
                const radian = (angle * Math.PI) / 180;
                
                // 7의 배수는 큰 틱 (0, 7, 14, 21... = 12개), 나머지는 작은 틱
                const isMainTick = (i % 7 === 0);
                const tickLength = isMainTick ? 15 : 8;
                const tickWidth = isMainTick ? 1.3 : 1.2;
                const tickOpacity = isMainTick ? 0.4 : 0.25;
                
                // 구분선 시작점 (바깥쪽)
                const x1 = 200 + (radius + tickLength) * Math.cos(radian);
                const y1 = 200 + (radius + tickLength) * Math.sin(radian);
                
                // 구분선 끝점 (안쪽)
                const x2 = 200 + (radius - tickLength) * Math.cos(radian);
                const y2 = 200 + (radius - tickLength) * Math.sin(radian);
                
                const tick = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                tick.setAttribute('x1', x1);
                tick.setAttribute('y1', y1);
                tick.setAttribute('x2', x2);
                tick.setAttribute('y2', y2);
                tick.setAttribute('stroke', '#555555');
                tick.setAttribute('stroke-width', tickWidth);
                tick.setAttribute('opacity', tickOpacity);
                
                ticksContainer.appendChild(tick);
            }
        }

        // 진행바 업데이트 함수 (남은 시간에 따라 원이 줄어듦)
        function setProgress(percent) {
            const totalSeconds = <?= $settings['minutes'] * 60 + (isset($settings['seconds']) ? $settings['seconds'] : 0) ?>;
            
            // 남은 시간을 기준으로 정확한 퍼센트 계산
            const exactPercent = (remainingSeconds / totalSeconds) * 100;
            
            // 틱과 동일한 방식으로 계산
            // exactPercent가 100%일 때 offset = circumference (완전한 원)
            // exactPercent가 0%일 때 offset = 0 (원이 사라짐)
            const offset = circumference * (exactPercent / 100);
            progressRing.style.strokeDashoffset = offset;
            
            // 마지막 1분일 때 색상 변경
            if (remainingSeconds <= 60 && remainingSeconds > 0) {
                progressRing.style.stroke = '#0a0a0a'; // 진행된 부분 거의 검은색
                timerDisplay.style.color = '#404040'; // 타이머 숫자는 그대로 유지
            } else {
                progressRing.style.stroke = '#0a0a0a'; // 진행된 부분 거의 검은색
                timerDisplay.style.color = '#404040'; // 타이머 숫자도 어두운 회색
            }
        }
        
        // 타이머 디스플레이 업데이트
        function updateDisplay() {
            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;
            timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            // 진행률 계산 및 업데이트
            const progress = (remainingSeconds / TOTAL_SECONDS) * 100;
            console.log(`남은시간: ${remainingSeconds}초, 전체시간: ${TOTAL_SECONDS}초, 진행률: ${progress.toFixed(1)}%`);
            setProgress(progress);
            
            // 깜빡임 애니메이션
            if (isRunning && !isPaused) {
                startBlinkAnimation();
            }
        }
        
        // 깜빡임 애니메이션 (완전히 제거)
        function startBlinkAnimation() {
            // 애니메이션 없음 - 색상과 크기 완전 고정
            // 필요시 여기에 다른 효과 추가 가능
        }
        
        // 전체화면 해제 함수
        function exitFullscreen() {
            if (document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement) {
                
                if (document.exitFullscreen) {
                    document.exitFullscreen().then(() => {
                        console.log('전체화면 해제 완료');
                    }).catch(err => {
                        console.log('전체화면 해제 실패:', err);
                    });
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }

        // 안내 메시지 순환 표시 함수
        function startMessageRotation() {
            if (!GUIDE_MESSAGES || GUIDE_MESSAGES.length === 0) {
                console.log('안내 메시지가 없습니다');
                return;
            }
            
            const messageLine = guideMessage.querySelector('.message-line');
            
            function showNextMessage() {
                // 휴식 시간인지 확인
                if (isRestPeriod) {
                    messageLine.style.opacity = '0';
                    console.log('휴식 시간: 10초 대기');
                    
                    // 기존 타이머 정리
                    if (messageInterval) {
                        clearInterval(messageInterval);
                        messageInterval = null;
                        console.log('기존 타이머 정리됨');
                    }
                    
                    setTimeout(() => {
                        isRestPeriod = false;
                        currentMessageIndex = 0; // 첫 번째 메시지부터 다시 시작
                        console.log('휴식 후 다시 시작, 현재 인덱스:', currentMessageIndex);
                        
                        // 첫 번째 메시지 표시
                        showMessage();
                        
                        // 새로운 타이머 시작
                        messageInterval = setInterval(showNextMessage, 10000);
                        console.log('새로운 타이머 시작됨');
                    }, 10000); // 10초 휴식
                    return;
                }
                
                showMessage();
            }
            
            function showMessage() {
                // 현재 메시지 인덱스 확인
                console.log('메시지 표시:', currentMessageIndex, '/', GUIDE_MESSAGES.length);
                
                // Fade out
                messageLine.style.opacity = '0';
                
                setTimeout(() => {
                    // 인덱스가 유효한지 확인
                    if (currentMessageIndex >= GUIDE_MESSAGES.length) {
                        console.log('모든 메시지 완료, 휴식 시간 시작');
                        isRestPeriod = true;
                        return;
                    }
                    
                    // 메시지 변경
                    messageLine.textContent = GUIDE_MESSAGES[currentMessageIndex];
                    console.log('표시된 메시지:', GUIDE_MESSAGES[currentMessageIndex]);
                    
                    // Fade in
                    messageLine.style.opacity = '1';
                    
                    // 다음 메시지 인덱스 설정
                    currentMessageIndex++;
                    
                    // 모든 메시지를 다 보여줬으면 휴식 시간 설정
                    if (currentMessageIndex >= GUIDE_MESSAGES.length) {
                        console.log('다음 턴에 휴식 시간');
                        isRestPeriod = true;
                    }
                }, 2000); // fade out 시간 (조금 더 빠르게)
            }
            
            // 10초 후에 첫 번째 메시지 표시
            setTimeout(() => {
                console.log('첫 번째 메시지 표시 시작');
                messageLine.textContent = GUIDE_MESSAGES[currentMessageIndex];
                messageLine.style.opacity = '1';
                console.log('첫 번째 메시지:', GUIDE_MESSAGES[currentMessageIndex]);
                currentMessageIndex = 1; // 다음은 두 번째 메시지
                
                // 10초마다 메시지 변경
                messageInterval = setInterval(showNextMessage, 10000);
            }, 10000); // 10초 지연
            
            console.log('안내 메시지 순환 시작:', GUIDE_MESSAGES);
        }
        
        function stopMessageRotation() {
            if (messageInterval) {
                clearInterval(messageInterval);
                messageInterval = null;
                console.log('안내 메시지 순환 중지');
            }
        }

        // 타이머 메인 로직
        function startTimer() {
            // 이미 실행 중인 타이머가 있으면 중지
            if (timerInterval) {
                clearInterval(timerInterval);
                console.log('기존 타이머 중지됨');
            }
            
            timerInterval = setInterval(() => {
                if (isRunning && !isPaused) {
                    remainingSeconds--;
                    updateDisplay();
                    
                    // 0초가 되면 1초 후에 종료 (0초를 1초간 표시)
                    if (remainingSeconds <= 0) {
                        setTimeout(() => {
                            timerFinished();
                        }, 1000);
                        clearInterval(timerInterval); // 타이머 중지
                    }
                }
            }, 1000);
            console.log('새 타이머 시작됨');
        }
        
        // 타이머 완료
        function timerFinished() {
            clearInterval(timerInterval);
            isRunning = false;
            
            // 음악 페이드 아웃 효과
            if (backgroundMusic && !backgroundMusic.paused) {
                fadeOutMusic(backgroundMusic, 2000); // 2초에 걸쳐 페이드 아웃
            }
            
            // 진행바 숨기기
            document.querySelector('.circular-progress').style.display = 'none';
            
            // 컨트롤 버튼들이 삭제되어 숨길 필요 없음
            
            // 제목 표시 유지 (종료 화면에서도 제목이 보이도록)
            const timerTitle = document.querySelector('.timer-title');
            if (timerTitle) {
                timerTitle.style.display = 'block';
            }
            
            // 안내 메시지 숨김 (타이머 완료)
            guideMessage.style.display = 'none';
            stopMessageRotation();
            
            // 타이머 완료 후 전체화면 상태 유지
            console.log('타이머 완료 - 전체화면 유지');
            
            // 안내 메시지 제거 (사용자 요청)
        }
        
        // 일시정지/재생 토글
        function togglePause() {
            isPaused = !isPaused;
            
            if (backgroundMusic) {
                if (isPaused) {
                    backgroundMusic.pause();
                } else {
                    backgroundMusic.play();
                }
            }
            
            console.log(isPaused ? '타이머 일시정지' : '타이머 재개');
        }
        
        // 타이머 정지 (수동 정지 - 트레이로 보내지 않음)
        function stopTimer() {
            clearInterval(timerInterval);
            isRunning = false;
            
            // 음악 페이드 아웃 효과 (빠른 페이드 아웃)
            if (backgroundMusic && !backgroundMusic.paused) {
                fadeOutMusic(backgroundMusic, 1000); // 1초에 걸쳐 페이드 아웃
            }
            
            // 전체화면 해제 후 설정 페이지로 이동
            exitFullscreen();
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 300);
        }
        
        // 함수들을 먼저 정의
        
        // 현재 시간 표시 관련 변수
        let currentTimeInterval = null;
        let autoStartInterval = null;
        
        // 현재 시간 표시 요소 생성
        function createCurrentTimeDisplay() {
            // 기존 현재 시간 요소가 있으면 제거
            const existingTimeDisplay = document.getElementById('currentTimeDisplay');
            if (existingTimeDisplay) {
                existingTimeDisplay.remove();
            }
            
            // 기존 타이머 시작시간 요소가 있으면 제거
            const existingStartTimeDisplay = document.getElementById('startTimeDisplay');
            if (existingStartTimeDisplay) {
                existingStartTimeDisplay.remove();
            }
            
            // 현재 시간 표시 요소 생성 (통합된 블록에서 처리하므로 숨김)
            const currentTimeDisplay = document.createElement('div');
            currentTimeDisplay.id = 'currentTimeDisplay';
            currentTimeDisplay.style.cssText = `
                display: none;
            `;
            
            // 통합된 시간 표시 블록 생성
            const startTimeDisplay = document.createElement('div');
            startTimeDisplay.id = 'startTimeDisplay';
            startTimeDisplay.style.cssText = `
                position: fixed;
                top: 70vh;
                left: 50%;
                transform: translateX(-50%);
                color: #1a1a1a;
                font-size: clamp(12px, 1.5vw, 18px);
                font-weight: normal;
                text-align: left;
                z-index: 999;
                font-family: 'Courier New', monospace;
                opacity: 0.7;
            `;
            
            // 전체화면 안내 메시지 요소 생성
            const fullscreenNotice = document.createElement('div');
            fullscreenNotice.id = 'fullscreenNotice';
            fullscreenNotice.style.cssText = `
                position: fixed;
                top: 85vh;
                left: 50%;
                transform: translateX(-50%);
                color: #888888;
                font-size: clamp(14px, 2.2vw, 28px);
                font-weight: bold;
                text-align: center;
                z-index: 1000;
                font-family: 'Arial', sans-serif;
                background: rgba(136, 136, 136, 0.1);
                padding: 8px 16px;
                border-radius: 8px;
                border: 1px solid rgba(136, 136, 136, 0.3);
                animation: pulse 2s infinite;
                display: none;
                cursor: pointer;
            `;
            fullscreenNotice.innerHTML = '전체 화면으로 전환하세요<br><span style="font-size: 0.7em; opacity: 0.8;">(스페이스바를 누르거나, 클릭)</span>';
            
            // 클릭 시 전체화면 전환
            fullscreenNotice.addEventListener('click', function() {
                if (isReady && !isFullscreenReady) {
                    console.log('전체화면 안내 메시지 클릭: 전체화면 전환');
                    toggleFullscreen();
                    isFullscreenReady = true;
                }
            });
            
            document.body.appendChild(currentTimeDisplay);
            document.body.appendChild(startTimeDisplay);
            document.body.appendChild(fullscreenNotice);
            
            // 타이머 시작시간 표시
            updateStartTimeDisplay();
            
            // 전체화면 상태 체크 및 안내 메시지 표시
            updateFullscreenNotice();
            
            // 현재 시간 업데이트 시작
            updateCurrentTime();
            currentTimeInterval = setInterval(updateCurrentTime, 1000);
            
            // 자동 시작 시간 체크 시작
            checkAutoStart();
            autoStartInterval = setInterval(checkAutoStart, 1000);
            
            // 전체화면 상태 변화 감지
            document.addEventListener('fullscreenchange', updateFullscreenNotice);
            document.addEventListener('webkitfullscreenchange', updateFullscreenNotice);
            document.addEventListener('mozfullscreenchange', updateFullscreenNotice);
            document.addEventListener('MSFullscreenChange', updateFullscreenNotice);
            
            // 주기적으로 전체화면 상태 체크 (안전장치)
            setInterval(updateFullscreenNotice, 1000);
        }
        
        // 현재 시간 업데이트 (통합된 시간 블록에서 처리)
        function updateCurrentTime() {
            // 통합된 시간 표시 업데이트
            updateStartTimeDisplay();
        }
        
        // 통합된 시간 표시 업데이트
        function updateStartTimeDisplay() {
            const startTimeDisplay = document.getElementById('startTimeDisplay');
            if (startTimeDisplay) {
                // 현재 시간 가져오기
                const now = new Date();
                const currentHours = String(now.getHours()).padStart(2, '0');
                const currentMinutes = String(now.getMinutes()).padStart(2, '0');
                const currentSeconds = String(now.getSeconds()).padStart(2, '0');
                
                const autoStartHour = <?= isset($settings['auto_start_hour']) ? $settings['auto_start_hour'] : -1 ?>;
                const autoStartMinute = <?= isset($settings['auto_start_minute']) ? $settings['auto_start_minute'] : 0 ?>;
                
                let timeDisplayHTML = `현재시간: ${currentHours}시 ${currentMinutes}분 ${currentSeconds}초<br>`;
                
                if (autoStartHour === -1) {
                    timeDisplayHTML += '자동 시작 사용 안함';
                } else {
                    const hourStr = String(autoStartHour).padStart(2, '0');
                    const minuteStr = String(autoStartMinute).padStart(2, '0');
                    
                    // 시작 시간 계산
                    const startTime = new Date();
                    startTime.setHours(autoStartHour, autoStartMinute, 0, 0);
                    
                    // 시작 시간이 현재 시간보다 이전이면 다음날로 설정
                    if (startTime <= now) {
                        startTime.setDate(startTime.getDate() + 1);
                    }
                    
                    const timeDiff = startTime - now;
                    const remainingHours = Math.floor(timeDiff / (1000 * 60 * 60));
                    const remainingMinutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                    const remainingSeconds = Math.floor((timeDiff % (1000 * 60)) / 1000);
                    
                    timeDisplayHTML += `시작시간: ${hourStr}시 ${minuteStr}분<br>`;
                    
                    // 남은 시간 표시 (0인 단위는 생략)
                    let remainingTimeStr = '남은시간: ';
                    if (remainingHours > 0) {
                        remainingTimeStr += `${remainingHours}시간 `;
                    }
                    if (remainingMinutes > 0) {
                        remainingTimeStr += `${remainingMinutes}분 `;
                    }
                    remainingTimeStr += `${remainingSeconds}초`;
                    
                    timeDisplayHTML += remainingTimeStr;
                }
                
                startTimeDisplay.innerHTML = timeDisplayHTML;
            }
        }
        
        // 전체화면 안내 메시지 업데이트
        function updateFullscreenNotice() {
            const fullscreenNotice = document.getElementById('fullscreenNotice');
            if (fullscreenNotice && isReady) {
                const isFullscreen = document.fullscreenElement || 
                                   document.webkitFullscreenElement || 
                                   document.mozFullScreenElement || 
                                   document.msFullscreenElement;
                
                if (!isFullscreen) {
                    fullscreenNotice.style.display = 'block';
                } else {
                    fullscreenNotice.style.display = 'none';
                }
            }
        }
        
        // 현재 시간 표시 제거
        function removeCurrentTimeDisplay() {
            const currentTimeDisplay = document.getElementById('currentTimeDisplay');
            if (currentTimeDisplay) {
                currentTimeDisplay.remove();
            }
            
            const startTimeDisplay = document.getElementById('startTimeDisplay');
            if (startTimeDisplay) {
                startTimeDisplay.remove();
            }
            
            const fullscreenNotice = document.getElementById('fullscreenNotice');
            if (fullscreenNotice) {
                fullscreenNotice.remove();
            }
            
            if (currentTimeInterval) {
                clearInterval(currentTimeInterval);
                currentTimeInterval = null;
            }
            
            if (autoStartInterval) {
                clearInterval(autoStartInterval);
                autoStartInterval = null;
            }
        }
        
        // 자동 시작 시간 체크
        function checkAutoStart() {
            const autoStartHour = <?= isset($settings['auto_start_hour']) ? $settings['auto_start_hour'] : -1 ?>;
            const autoStartMinute = <?= isset($settings['auto_start_minute']) ? $settings['auto_start_minute'] : 0 ?>;
            
            // 자동 시작이 비활성화된 경우
            if (autoStartHour === -1) {
                return;
            }
            
            // 준비 상태가 아닌 경우 체크하지 않음
            if (!isReady) {
                return;
            }
            
            const now = new Date();
            const currentHour = now.getHours();
            const currentMinute = now.getMinutes();
            const currentSecond = now.getSeconds();
            
            // 설정된 시간과 일치하고 0초인 경우 자동 시작
            if (currentHour === autoStartHour && currentMinute === autoStartMinute && currentSecond === 0) {
                console.log(`자동 시작: ${autoStartHour}시 ${autoStartMinute}분`);
                
                // 전체화면이 아니면 먼저 전체화면으로 전환
                if (!isFullscreenReady) {
                    toggleFullscreen();
                    isFullscreenReady = true;
                    
                    // 전체화면 전환 후 잠시 대기 후 타이머 시작
                    setTimeout(() => {
                        if (isReady && isFullscreenReady) {
                            startTimerFromReady();
                        }
                    }, 500);
                } else {
                    // 이미 전체화면이면 바로 타이머 시작
                    startTimerFromReady();
                }
            }
        }
        
        // 음악 페이드 아웃 함수
        function fadeOutMusic(audioElement, duration) {
            if (typeof duration === 'undefined') {
                duration = 2000;
            }
            if (!audioElement || audioElement.paused) return;
            
            const originalVolume = audioElement.volume;
            const fadeStep = originalVolume / (duration / 50); // 50ms마다 볼륨 감소
            
            const fadeInterval = setInterval(() => {
                if (audioElement.volume > fadeStep) {
                    audioElement.volume -= fadeStep;
                } else {
                    audioElement.volume = 0;
                    audioElement.pause();
                    audioElement.volume = originalVolume; // 원래 볼륨으로 복원 (다음 재생을 위해)
                    clearInterval(fadeInterval);
                    console.log('음악 페이드 아웃 완료');
                }
            }, 50);
            
            console.log(`음악 페이드 아웃 시작 (${duration}ms)`);
        }
        
        // 전체화면 토글 함수
        function toggleFullscreen() {
            if (!document.fullscreenElement && 
                !document.webkitFullscreenElement && 
                !document.mozFullScreenElement && 
                !document.msFullscreenElement) {
                // 전체화면 진입
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(e => {
                        console.log('전체화면 모드를 지원하지 않습니다:', e);
                        alert('전체화면 모드가 지원되지 않습니다. F11 키를 눌러보세요.');
                    });
                } else if (document.documentElement.webkitRequestFullscreen) {
                    document.documentElement.webkitRequestFullscreen();
                } else if (document.documentElement.mozRequestFullScreen) {
                    document.documentElement.mozRequestFullScreen();
                } else if (document.documentElement.msRequestFullscreen) {
                    document.documentElement.msRequestFullscreen();
                } else {
                    alert('전체화면 모드가 지원되지 않습니다. F11 키를 눌러보세요.');
                }
            } else {
                // 전체화면 해제
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }
        
        // 타이머 상태 관리
        let isReady = false; // 준비 상태
        let isFullscreenReady = false; // 전체화면 준비 상태
        
        // 준비 상태 표시
        function showReadyState() {
            isReady = true;
            isFullscreenReady = false;
            isRunning = false;
            
            // CSS에서 기본적으로 숨겨져 있으므로 별도 숨김 처리 불필요
            
            // 제목만 표시 (큰 크기로 표시)
            const timerTitle = document.querySelector('.timer-title');
            timerTitle.style.display = 'block';
            timerTitle.style.fontSize = 'clamp(20px, 4.5vw, 54px)'; // 더 줄인 크기로 설정
            timerTitle.style.cursor = 'pointer'; // 클릭 가능하다는 것을 표시
            
            // 제목 클릭 이벤트 리스너 추가 (스페이스키와 동일한 기능)
            timerTitle.addEventListener('click', handleReadyStateClick);
            
            // 진행바 구분선 미리 생성 (준비 상태에서도 보이도록)
            createProgressTicks();
            
            // 현재 시간 표시 요소 생성
            createCurrentTimeDisplay();
            
            console.log('준비 상태: 제목과 현재 시간 표시');
        }
        
        // 대기 상태에서 제목 클릭 처리 (스페이스키와 동일한 기능)
        function handleReadyStateClick() {
            // 준비 상태에서 클릭 1번: 전체화면 전환
            if (isReady && !isFullscreenReady) {
                console.log('제목 클릭 1번: 전체화면 전환');
                toggleFullscreen();
                isFullscreenReady = true;
                return;
            }
            
            // 전체화면 준비 상태에서 클릭 2번: 타이머 시작
            if (isReady && isFullscreenReady) {
                console.log('제목 클릭 2번: 타이머 시작');
                startTimerFromReady();
                return;
            }
        }
        
        // 타이머 화면에서 제목 클릭 처리 (일시정지/재생 토글)
        function handleTimerStateClick() {
            if (isRunning) {
                console.log('제목 클릭: 일시정지/재생 토글');
                togglePause();
            }
        }
        
        // 타이머 시작 (준비 상태에서 실행 상태로)
        function startTimerFromReady() {
            if (!isReady) return;
            
            isReady = false;
            isFullscreenReady = false;
            isRunning = true;
            
            // 준비 메시지 제거 (이제 메시지가 없으므로 불필요)
            
            // 현재 시간 표시 제거
            removeCurrentTimeDisplay();
            
            // 안내 메시지 표시 (타이머 시작 시)
            if (guideMessage && GUIDE_MESSAGES.length > 0) {
                guideMessage.style.display = 'block';
                startMessageRotation();
            } else {
                console.log('안내 메시지가 없거나 요소를 찾을 수 없음');
            }
            
            // 타이머 디스플레이 컨테이너 표시
            const timerDisplayContainer = document.querySelector('.timer-display-container');
            if (timerDisplayContainer) {
                timerDisplayContainer.style.display = 'flex'; // CSS 기본값이 none이므로 flex로 변경
            }
            
            // 진행바 다시 보이기
            const progressRing = document.querySelector('.progress-ring-circle');
            if (progressRing) {
                progressRing.style.visibility = 'visible';
                progressRing.style.opacity = '1';
            }
            
            // 컨트롤 버튼들이 삭제되어 표시할 필요 없음
            
            // 타이머 화면에서 클릭 기능 추가 (제목, 타이머 숫자, 진행바)
            const timerTitle = document.querySelector('.timer-title');
            const timerDisplay = document.querySelector('.timer-display');
            const circularProgress = document.querySelector('.circular-progress');
            
            if (timerTitle) {
                timerTitle.style.cursor = 'pointer'; // 클릭 가능하다는 것을 표시
                // 기존 이벤트 리스너 제거 후 새로운 기능 추가
                timerTitle.removeEventListener('click', handleReadyStateClick);
                timerTitle.addEventListener('click', handleTimerStateClick);
            }
            
            if (timerDisplay) {
                timerDisplay.style.cursor = 'pointer'; // 클릭 가능하다는 것을 표시
                timerDisplay.addEventListener('click', handleTimerStateClick);
            }
            
            if (circularProgress) {
                circularProgress.style.cursor = 'pointer'; // 클릭 가능하다는 것을 표시
                circularProgress.addEventListener('click', handleTimerStateClick);
            }
            
            // 타이머 시작
            updateDisplay();
            startTimer();
            
            // 음악 재생 시작 (타이머 시작과 함께)
            if (backgroundMusic) {
                console.log('타이머 시작과 함께 음악 재생 시도');
                backgroundMusic.play().then(() => {
                    console.log('음악 재생 성공');
                }).catch(e => {
                    console.log('음악 자동 재생 차단:', e.message);
                    showMusicPlayButton();
                });
            }
            
            console.log('타이머 시작됨');
        }
        
        // 즉시 초기화 (스크립트가 body 끝에 있으므로 DOM 요소들이 이미 로드됨)
        setTimeout(() => {
            showReadyState(); // 준비 상태로 시작
        }, 100); // 약간의 지연을 두어 확실히 DOM이 준비되도록
        
        // 음악 로드만 (재생은 타이머 시작 시)
        if (backgroundMusic) {
            console.log('음악 요소 발견:', backgroundMusic.src);
            console.log('준비 상태: 음악 로드만 하고 재생하지 않음');
            
            // 음악 상태 이벤트 리스너들
            backgroundMusic.addEventListener('loadstart', () => {
                console.log('음악 로드 시작');
            });
            
            backgroundMusic.addEventListener('loadeddata', () => {
                console.log('음악 데이터 로드됨');
            });
            
            backgroundMusic.addEventListener('canplay', () => {
                console.log('음악 재생 준비 완료 (준비 상태에서는 재생하지 않음)');
            });
            
            backgroundMusic.addEventListener('canplaythrough', () => {
                console.log('음악 완전 로드됨 (준비 상태에서는 재생하지 않음)');
            });
            
            backgroundMusic.addEventListener('error', (e) => {
                console.error('음악 로드 오류:', e);
                console.error('오류 코드:', backgroundMusic.error?.code);
                console.error('오류 메시지:', backgroundMusic.error?.message);
            });
            
            backgroundMusic.addEventListener('play', () => {
                console.log('음악 재생 시작됨');
            });
            
            backgroundMusic.addEventListener('pause', () => {
                console.log('음악 일시정지됨');
            });
            
            // 음악 로드만 시작 (재생은 하지 않음)
            backgroundMusic.load();
        }
        </script>
    
    <script>
        // 수동 재생 버튼 표시
        function showMusicPlayButton() {
            const playButton = document.createElement('button');
            playButton.textContent = '🎵 음악 재생';
            playButton.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #00ffff;
                color: #000;
                border: none;
                padding: 10px 20px;
                border-radius: 5px;
                cursor: pointer;
                z-index: 1000;
                font-size: 14px;
            `;
            
            playButton.onclick = () => {
                if (typeof backgroundMusic !== 'undefined' && backgroundMusic) {
                    backgroundMusic.play().then(() => {
                        console.log('수동 음악 재생 성공');
                        playButton.remove();
                    }).catch(err => {
                        console.error('수동 음악 재생 실패:', err);
                    });
                }
            };
            
            document.body.appendChild(playButton);
            
            // 5초 후 자동 제거
            setTimeout(() => {
                if (playButton.parentNode) {
                    playButton.remove();
                }
            }, 5000);
        }
        
        // 전체화면 토글
        function toggleFullscreen() {
            if (!document.fullscreenElement && 
                !document.webkitFullscreenElement && 
                !document.mozFullScreenElement && 
                !document.msFullscreenElement) {
                // 전체화면 진입
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(e => {
                        console.log('전체화면 모드를 지원하지 않습니다:', e);
                        alert('전체화면 모드가 지원되지 않습니다. F11 키를 눌러보세요.');
                    });
                } else if (document.documentElement.webkitRequestFullscreen) {
                    document.documentElement.webkitRequestFullscreen();
                } else if (document.documentElement.mozRequestFullScreen) {
                    document.documentElement.mozRequestFullScreen();
                } else if (document.documentElement.msRequestFullscreen) {
                    document.documentElement.msRequestFullscreen();
                } else {
                    alert('전체화면 모드가 지원되지 않습니다. F11 키를 눌러보세요.');
                }
            } else {
                // 전체화면 해제
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }
        
        // 전체화면 상태 변화 감지 (버튼이 없으므로 빈 함수)
        function updateFullscreenButton() {
            // 전체화면 버튼이 삭제되어 더 이상 업데이트할 필요 없음
        }
        
        // 전체화면 상태 변화 이벤트 리스너
        document.addEventListener('fullscreenchange', updateFullscreenButton);
        document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
        document.addEventListener('mozfullscreenchange', updateFullscreenButton);
        document.addEventListener('MSFullscreenChange', updateFullscreenButton);
        
        // 페이지 로드 후 초기화 (자동 시작 제거)
        document.addEventListener('DOMContentLoaded', () => {
            updateFullscreenButton();
            updateDisplay();
            
            // 준비 상태로 시작 (자동 타이머 시작 제거)
            console.log('페이지 로드됨 - 준비 상태로 대기');
        });
        
        // 전체화면 시도 및 타이머 시작
        function attemptFullscreenAndStartTimer() {
            const element = document.documentElement;
            
            // 이미 전체화면인지 확인
            if (document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement) {
                console.log('이미 전체화면 상태, 타이머 시작');
                startTimerNow();
                return;
            }
            
            // 전체화면 요청
            let fullscreenPromise;
            if (element.requestFullscreen) {
                fullscreenPromise = element.requestFullscreen();
            } else if (element.webkitRequestFullscreen) {
                fullscreenPromise = element.webkitRequestFullscreen();
            } else if (element.mozRequestFullScreen) {
                fullscreenPromise = element.mozRequestFullScreen();
            } else if (element.msRequestFullscreen) {
                fullscreenPromise = element.msRequestFullscreen();
            }
            
            if (fullscreenPromise) {
                fullscreenPromise.then(() => {
                    console.log('타이머 페이지에서 전체화면 전환 성공');
                    startTimerNow();
                }).catch((error) => {
                    console.log('타이머 페이지에서 전체화면 전환 실패:', error);
                    // 전체화면 실패 시에만 안내 표시
                    if (!document.fullscreenElement && 
                        !document.webkitFullscreenElement && 
                        !document.mozFullScreenElement && 
                        !document.msFullscreenElement) {
                        showFullscreenPrompt();
                    }
                    startTimerNow();
                });
            } else {
                console.log('전체화면 API 지원 안함');
                // 전체화면 API가 없고 현재 전체화면이 아닌 경우에만 안내 표시
                if (!document.fullscreenElement && 
                    !document.webkitFullscreenElement && 
                    !document.mozFullScreenElement && 
                    !document.msFullscreenElement) {
                    showFullscreenPrompt();
                }
                startTimerNow();
            }
        }
        
        // 타이머 즉시 시작
        function startTimerNow() {
            // 이미 실행 중이면 중복 시작 방지
            if (isRunning) {
                console.log('타이머가 이미 실행 중입니다');
                return;
            }
            
            isRunning = true;
            
            // 안내 메시지 표시 (타이머 시작 시)
            if (guideMessage && GUIDE_MESSAGES.length > 0) {
                guideMessage.style.display = 'block';
                startMessageRotation();
            } else {
                console.log('안내 메시지가 없거나 요소를 찾을 수 없음');
            }
            
            startTimer();
            console.log('타이머 자동 시작됨');
        }
        
        // 전체화면 안내 표시 (비활성화)
        function showFullscreenPrompt() {
            // 깜빡임 방지를 위해 함수 비활성화
            console.log('전체화면 안내 표시 요청됨 (비활성화됨)');
            // 더 이상 버튼 깜빡임 없음
        }
        
        // 키보드 이벤트 리스너 추가
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                // 타이머가 완료된 상태인지 확인
                if (!isRunning && !messageInterval) {
                    // 타이머 완료 후 ESC: 설정 페이지로 이동
                    exitFullscreen();
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 300);
                } else {
                    // 타이머 실행 중 ESC: 일반 정지
                    stopTimer();
                }
            } else if (e.key === ' ') {
                e.preventDefault();
                
                // 준비 상태에서 스페이스바 1번: 전체화면 전환
                if (isReady && !isFullscreenReady) {
                    console.log('스페이스바 1번: 전체화면 전환');
                    toggleFullscreen();
                    isFullscreenReady = true;
                    return;
                }
                
                // 전체화면 준비 상태에서 스페이스바 2번: 타이머 시작
                if (isReady && isFullscreenReady) {
                    console.log('스페이스바 2번: 타이머 시작');
                    startTimerFromReady();
                    return;
                }
                
                // 타이머 실행 중 스페이스바: 일시정지/재생
                if (isRunning) {
                    togglePause();
                }
            } else if (e.key === 'F11') {
                e.preventDefault();
                toggleFullscreen();
            }
        });
        
        // 컨트롤 버튼들이 삭제되어 이벤트 리스너 등록 불필요
        
        // 즉시 준비 상태로 시작 (타이머 자동 시작 방지)
        setTimeout(() => {
            showReadyState(); // 준비 상태로 시작
        }, 100);
        
    </script>
</body>
</html>
