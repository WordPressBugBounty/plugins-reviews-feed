import { __ } from "@wordpress/i18n";
import SbUtils from "../../../Utils/SbUtils";
import Button from "../../Common/Button";

const DashboardHeader = ( props ) => {
    return (
            <>
                <div className='sb-dashboard-header-logo'>
                    { SbUtils.printIcon( 'full-logo' ) }
                    {
                        props?.heading &&
                        <span className='sb-dashboard-header-haeding sb-text-small'> / {props.heading} </span>
                    }
                </div>
                <div className='sb-header-action-btns'>
                    {
                        props.showHelpButton !== undefined && props.showHelpButton === true &&
                        <Button
                            type='secondary'
                            size='medium'
                            icon='help'
                            text={ __( 'Help', 'sb-customizer' ) }
                            link='?page=sbr-support'
                            target='_self'
                        />
                    }
                </div>
            </>
    )
}
export default DashboardHeader;