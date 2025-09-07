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
    <link rel="stylesheet" href="style.css">
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
                    </svg>
                           <div class="timer-display" id="timerDisplay">
                               <?= sprintf('%02d:%02d', $settings['minutes'], isset($settings['seconds']) ? $settings['seconds'] : 0) ?>
                           </div>
                    <div class="music-info" id="musicInfo"></div>
                </div>
            </div>
            
            <div class="timer-controls">
                <button id="fullscreenBtn" class="control-btn" title="전체화면 (F11 또는 Space)">⛶</button>
                <button id="pauseBtn" class="control-btn" title="일시정지/재생 (전체화면에서 Space)">⏸</button>
                <button id="stopBtn" class="control-btn" title="정지 (ESC)">⏹</button>
            </div>
            
            <div id="endMessage" class="end-message" style="display: none;">
                <?= htmlspecialchars($settings['end_message']) ?>
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
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        const pauseBtn = document.getElementById('pauseBtn');
        const stopBtn = document.getElementById('stopBtn');
        const endMessage = document.getElementById('endMessage');
        const progressRing = document.querySelector('.progress-ring-circle');
        // backgroundMusic은 위에서 이미 선언됨
        
        // 진행바 설정
        const radius = progressRing.r.baseVal.value;
        const circumference = radius * 2 * Math.PI;
        progressRing.style.strokeDasharray = `${circumference} ${circumference}`;
        progressRing.style.strokeDashoffset = 0; // 초기값을 0으로 설정 (100% 상태)
        
        // 진행바 업데이트 함수 (남은 시간에 따라 원이 줄어듦)
        function setProgress(percent) {
            // percent가 100%일 때 offset = 0 (완전한 원)
            // percent가 0%일 때 offset = circumference (원이 사라짐)
            const offset = circumference * (1 - percent / 100);
            progressRing.style.strokeDashoffset = offset;
            
            // 마지막 10초일 때 색상 변경
            if (remainingSeconds <= 10 && remainingSeconds > 0) {
                progressRing.style.stroke = '#606060'; // 진행바 어두운 회색
                timerDisplay.style.color = '#606060'; // 타이머 숫자도 어두운 회색
            } else {
                progressRing.style.stroke = '#808080'; // 진행바 회색
                timerDisplay.style.color = '#808080'; // 타이머 숫자도 회색
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
        
        // 깜빡임 애니메이션 (크기 증가 + 페이드 아웃 효과)
        function startBlinkAnimation() {
            // 애니메이션 시작: 크게 확대 + 불투명도 증가
            timerDisplay.style.transform = 'translate(-50%, -50%) scale(1.15)'; // 1.03에서 1.15로 증가
            timerDisplay.style.fontWeight = '900';
            timerDisplay.style.opacity = '1';
            timerDisplay.style.transition = 'transform 0.1s ease-out, opacity 0.3s ease-out, color 0.1s ease-out';
            
            // 마지막 10초일 때는 어두운 회색, 아니면 회색
            const animationColor = (remainingSeconds <= 10 && remainingSeconds > 0) ? '#606060' : '#808080';
            timerDisplay.style.color = animationColor;
            
            // 페이드 아웃 효과와 함께 원래 크기로 복원
            setTimeout(() => {
                timerDisplay.style.transform = 'translate(-50%, -50%) scale(1)';
                timerDisplay.style.fontWeight = 'bold';
                timerDisplay.style.opacity = '0.8'; // 페이드 아웃 효과
                // 10초 이하면 어두운 회색, 아니면 회색으로 복원
                timerDisplay.style.color = (remainingSeconds <= 10 && remainingSeconds > 0) ? '#606060' : '#808080';
            }, 100);
            
            // 완전히 원래 상태로 복원
            setTimeout(() => {
                timerDisplay.style.opacity = '1';
                timerDisplay.style.transition = 'none'; // 트랜지션 제거
            }, 400);
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
            
            // 컨트롤 버튼들 숨기기
            document.querySelector('.timer-controls').style.display = 'none';
            
            // 종료 메시지 표시
            endMessage.style.display = 'block';
            
            // 종료 메시지 표시 후 전체화면 상태 유지
            console.log('타이머 완료 - 종료 메시지 표시 중, 전체화면 유지');
            
            // 안내 메시지 제거 (사용자 요청)
        }
        
        // 일시정지/재생 토글
        function togglePause() {
            isPaused = !isPaused;
            pauseBtn.textContent = isPaused ? '▶' : '⏸';
            
            if (backgroundMusic) {
                if (isPaused) {
                    backgroundMusic.pause();
                } else {
                    backgroundMusic.play();
                }
            }
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
        
        // 음악 페이드 아웃 함수
        function fadeOutMusic(audioElement, duration = 2000) {
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
            
            // 제목만 표시 (원래 전체화면 크기와 동일)
            const timerTitle = document.querySelector('.timer-title');
            timerTitle.style.display = 'block';
            timerTitle.style.fontSize = 'clamp(20px, 4vw, 48px)'; // 원래 크기 유지
            
            console.log('준비 상태: 제목만 표시 (타이머 요소들은 CSS에서 기본 숨김)');
        }
        
        // 타이머 시작 (준비 상태에서 실행 상태로)
        function startTimerFromReady() {
            if (!isReady) return;
            
            isReady = false;
            isFullscreenReady = false;
            isRunning = true;
            
            // 준비 메시지 제거 (이제 메시지가 없으므로 불필요)
            
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
            
            // 버튼들 표시
            const timerControls = document.querySelector('.timer-controls');
            if (timerControls) {
                timerControls.style.display = 'flex'; // CSS 기본값이 none이므로 flex로 변경
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
        
        // 전체화면 상태 변화 감지
        function updateFullscreenButton() {
            if (document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement) {
                fullscreenBtn.innerHTML = '⛶';
                fullscreenBtn.title = '전체화면 해제 (F11)';
            } else {
                fullscreenBtn.innerHTML = '⛶';
                fullscreenBtn.title = '전체화면 (F11)';
            }
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
                if (!isRunning && endMessage.style.display === 'block') {
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
        
        // 이벤트 리스너 등록
        fullscreenBtn.addEventListener('click', toggleFullscreen);
        pauseBtn.addEventListener('click', togglePause);
        stopBtn.addEventListener('click', stopTimer);
        
        // 즉시 준비 상태로 시작 (타이머 자동 시작 방지)
        setTimeout(() => {
            showReadyState(); // 준비 상태로 시작
        }, 100);
        
    </script>
</body>
</html>
