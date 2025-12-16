-- ========================================
-- 3.sql: movie_insert 트리거
-- ========================================
-- 트리거 타입: BEFORE INSERT (행 레벨)
-- 대상 테이블: movie
-- 목적: movie 테이블에 새로운 영화 정보 삽입 시
--       누락된 컬럼(length, incolor, studioname, producerno)에 
--       자동으로 적절한 값을 채움
-- ========================================

create or replace trigger movie_insert
before insert on movie  -- movie 테이블에 INSERT 되기 전에 실행
for each row            -- 각 행마다 개별적으로 실행 (행 레벨 트리거)
declare
begin
    -- ===== 1. LENGTH (영화 상영시간) 처리 =====
    -- 영화 길이가 NULL인 경우 자동으로 값 설정
    if :new.length is null then
        -- 기존 영화들의 평균 상영시간으로 설정
        -- 업무 논리: 신규 영화의 길이가 명시되지 않은 경우,
        --          업계 평균을 기본값으로 사용
        select avg(length) into :new.length from movie;
    end if;
    
    -- ===== 2. INCOLOR (컬러/흑백 여부) 처리 =====
    -- 컬러 여부가 NULL인 경우 자동으로 값 설정
    if :new.incolor is null then
        -- 기본값으로 't' (true, 컬러) 설정
        -- 업무 논리: 현대 영화는 대부분 컬러이므로
        --          명시되지 않은 경우 컬러로 가정
        :new.incolor := 't';
    end if;
    
    -- ===== 3. STUDIONAME (제작 스튜디오) 처리 =====
    -- 제작 스튜디오가 NULL인 경우 자동으로 값 설정
    if :new.studioname is null then
        -- 기존 영화를 가장 적게 제작한 스튜디오를 선택
        -- (동률인 경우 랜덤 선택)
        select studioname into :new.studioname
        from (
            select studioname, count(*) cnt  -- 스튜디오별 제작 영화 수 계산
            from movie
            where studioname is not null     -- NULL이 아닌 스튜디오만
            group by studioname              -- 스튜디오별로 그룹화
            order by cnt,                    -- 적은 제작 수 순으로 정렬 (오름차순)
                     dbms_random.value       -- 동률일 경우 랜덤 정렬
        )
        where rownum = 1;  -- 첫 번째 행만 선택 (가장 적게 제작한 스튜디오)
        
        -- 업무 논리: 
        -- - 제작 스튜디오가 명시되지 않은 경우
        -- - 작업량이 적은 스튜디오에 우선 배정하여 업무 분산
        -- - 예: A 스튜디오 10편, B 스튜디오 5편 → B 스튜디오 선택
    end if;
    
    -- ===== 4. PRODUCERNO (프로듀서 인증번호) 처리 =====
    -- 프로듀서가 NULL인 경우 자동으로 값 설정
    if :new.producerno is null then
        -- 모든 임원(movieexec) 중에서 랜덤하게 한 명을 프로듀서로 선택
        select certno into :new.producerno
        from (
            select certno
            from movieexec                   -- 모든 영화 임원
            order by dbms_random.value       -- 랜덤 정렬
        )
        where rownum = 1;  -- 첫 번째 행만 선택 (랜덤 임원)
        
        -- 업무 논리:
        -- - 프로듀서가 명시되지 않은 경우
        -- - 모든 임원 중 랜덤하게 배정 (공평한 기회 제공)
    end if;
end;
/

-- ========================================
-- 트리거 동작 예시:
-- ========================================
-- 
-- INSERT 시도 (최소 정보만 제공):
-- INSERT INTO movie(title, year) VALUES ('New Movie', 2025);
--
-- 트리거 자동 처리:
-- - length: 예) 120 (기존 영화들의 평균 길이)
-- - incolor: 't' (컬러로 기본 설정)
-- - studioname: 예) 'Universal' (영화를 가장 적게 제작한 스튜디오)
-- - producerno: 예) 12345 (랜덤 임원의 인증번호)
--
-- 최종 삽입 데이터:
-- ('New Movie', 2025, 120, 't', 'Universal', 12345)
--
-- ========================================
-- 비즈니스 효과:
-- ========================================
-- 1. 데이터 완정성: 필수 정보 누락 방지
-- 2. 업무 효율: 수동 입력 최소화
-- 3. 균형 배분: 스튜디오와 프로듀서에게 공평한 작업 배정
-- 4. 표준화: 기본값 자동 설정으로 일관성 유지
-- ========================================
