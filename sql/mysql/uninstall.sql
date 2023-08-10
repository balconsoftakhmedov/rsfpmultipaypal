DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.email';
DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.return';
DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.test';
DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.cancel';
DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.language';
DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.tax.type';
DELETE FROM #__rsform_config WHERE SettingName = 'multipaypal.tax.value';

DELETE FROM #__rsform_component_types WHERE ComponentTypeId = 520;
DELETE FROM #__rsform_component_type_fields WHERE ComponentTypeId = 520;
