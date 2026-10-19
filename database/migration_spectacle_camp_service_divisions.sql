USE widms;

-- Service-centre camps have no GN Division, just like service-centre aid records.
ALTER TABLE spectacle_camp_participants
    MODIFY gn_division_id INT UNSIGNED NULL;
