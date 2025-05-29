import { __ } from '@wordpress/i18n'
import { useContext, useState } from 'react';
import SbUtils from '../../../../Utils/SbUtils';
import Button from '../../../Common/Button';
import Input from '../../../Common/Input';
import SettingsScreenContext from '../../../Context/SettingsScreenContext';

const ManageLicenseKeyFree = ( props ) => {
	const {
        sbSettings,
		sbCustomizer,
        editorNotification,
        editorTopLoader
    } = useContext( SettingsScreenContext) ;

	const [ isDevUrl, setIsDevUrl ] = useState( sbCustomizer.isDevUrl );
	const [ licenseStatus, setLicenseStatus] = useState( SbUtils.checkNotEmpty( sbCustomizer?.pluginSettings?.license_status ) ? sbCustomizer?.pluginSettings?.license_status  : null );
    const [ licenseKey, setLicenseKey ] = useState( sbCustomizer?.pluginSettings?.license_key || '' );
    const [ installLoading, setInstallLoading ] = useState( false );
    const [ licenseErrorMsg, setLicenseErrorMsg ] = useState( false );

	const installProVersion = () => {
		const notificationsContent = {
            success : {}
        },
            successNotification = {
                type : 'success',
                icon : 'success',
                text : __( 'License Activated!', 'sb-customizer' ),
                time : 10000
            },
            invalidNotification = {
                type : 'error',
                icon : 'notice',
                text : __( 'Invalid License!', 'sb-customizer' ),
                time : 5000
            };
			setLicenseErrorMsg(false)

		if( SbUtils.checkNotEmpty(licenseKey) ){
			setInstallLoading(true)
			const formData = {
				action : 'sbr_maybe_upgrade_redirect',
				license_key : licenseKey
			}
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					setInstallLoading(false)
					if ( data.success === false ) {
                    	notificationsContent.success = invalidNotification;
						if( typeof data.data !== 'undefined' ) {
							setLicenseErrorMsg(data.data.message)
							setTimeout(() => {
								setLicenseErrorMsg(false)
							}, 10000);
						}
					}
					if ( data.success === true ) {
						window.location.href = data.data.url
					}
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}
    }


	return (
        <>
			<span className='sb-text-small sb-dark-text sb-fs'>
				{ __( 'You are using the Lite version of the plugin–no license needed. Enjoy! 🙂 To unlock more features, consider ', 'sb-customizer' )  }
				<strong><a href='https://smashballoon.com/pricing/reviews-feed/' target='blank' rel='noreferrer'>{ __( 'Upgrading to Pro', 'sb-customizer' ) } </a></strong>
				<br/>
				{ __('As a valued user of our Lite plugin, you receive 50% OFF - automatically applied at checkout!', 'sb-customizer' ) }
            </span>
			<div className='sb-settings-input-ctn sb-fs'>
                <div>
					{
						!isDevUrl &&
						<>
						<Input
							size='medium'
							customClass={ `${'sb-license-key-' + licenseStatus}` + ' sb-fs' }
							type='password'
							value={ licenseKey }
							onChange={ ( event ) => {
								setLicenseKey( event.currentTarget.value )
							} }
							trailingIcon={ licenseStatus === 'valid' ? 'success' : 'notice' }
							/>
							<Button
								text={__( 'Install Pro', 'sb-customizer' ) }
								customClass='sb-installpro-btn'
								type='primary'
								size='medium'
								icon={ installLoading === true ? 'loader' : false }
								loading={installLoading}
								onClick={ () => {
									installProVersion()
								} }
							/>
						</>

					}
					{
						isDevUrl &&
						 <Button
							text={ __( 'Upgrade to Pro', 'sb-customizer' ) }
							type='primary'
							size='medium'
							icon='rocket'
							link='https://smashballoon.com/pricing/reviews-feed/'
							target='_blank'
						/>
					}
				</div>
			</div>
			{
				licenseErrorMsg !== false &&
				<strong className='sb-settings-free-liceseerror-ctn sb-fs' dangerouslySetInnerHTML={{ __html: licenseErrorMsg }}></strong>
			}
		</>
	)

}


export default ManageLicenseKeyFree;
